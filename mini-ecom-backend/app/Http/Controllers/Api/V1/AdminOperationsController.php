<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Admin\RecordAdminAuditEvent;
use App\Enums\InventoryAdjustmentReason;
use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminAuditEventResource;
use App\Http\Resources\DeliverySlotResource;
use App\Http\Resources\InventoryAdjustmentResource;
use App\Http\Resources\InventoryResource;
use App\Models\AdminAuditEvent;
use App\Models\DeliverySlot;
use App\Models\Inventory;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminOperationsController extends Controller
{
    public function __construct(private readonly RecordAdminAuditEvent $audit) {}

    public function inventory(Request $request): JsonResponse
    {
        $data = $request->validate(['perPage' => ['nullable', 'integer', 'between:1,100'], 'lowStock' => ['nullable', 'boolean']]);
        $query = Inventory::query()->with('product')->orderBy('product_id');
        if (($data['lowStock'] ?? false) === true) {
            $query->whereColumn('quantity_available', '<=', 'low_stock_threshold');
        }
        $page = $query->paginate($data['perPage'] ?? 50);

        return response()->json(['data' => InventoryResource::collection($page->items()), 'page' => $this->page($page)]);
    }

    public function adjustInventory(Request $request, string $productId): InventoryResource
    {
        $data = $request->validate([
            'delta' => ['required', 'numeric', 'not_in:0,0.0,0.00,0.000'],
            'reason' => ['required', Rule::in([
                InventoryAdjustmentReason::Restock->value,
                InventoryAdjustmentReason::Shrinkage->value,
                InventoryAdjustmentReason::Correction->value,
                InventoryAdjustmentReason::Return->value,
            ])],
            'note' => ['nullable', 'string', 'max:500'],
            'restockExpectedAt' => ['nullable', 'date'],
        ]);
        $product = Product::query()->wherePublicId($productId)->first();
        if ($product === null) {
            throw ProblemException::notFound('No such product.');
        }

        $inventory = DB::transaction(function () use ($request, $data, $product): Inventory {
            $locked = Inventory::query()->with('product')->lockForUpdate()->findOrFail($product->id);
            $before = $this->inventorySnapshot($locked);
            $delta = Money::round((string) $data['delta'], Money::QUANTITY_SCALE);
            $next = Money::add($locked->quantity_on_hand, $delta, Money::QUANTITY_SCALE);
            if (Money::compare($next, $locked->quantity_reserved, Money::QUANTITY_SCALE) < 0) {
                throw ProblemException::inventoryAdjustmentWouldOversell($locked->quantity_reserved);
            }

            $locked->update([
                'quantity_on_hand' => $next,
                'restock_expected_at' => array_key_exists('restockExpectedAt', $data) ? $data['restockExpectedAt'] : $locked->restock_expected_at,
            ]);
            InventoryAdjustment::create([
                'product_id' => $locked->product_id,
                'delta' => $delta,
                'reason' => InventoryAdjustmentReason::from($data['reason']),
                'note' => $data['note'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            $this->audit->handle($request->user(), 'inventory.adjusted', $locked->product, $before, $this->inventorySnapshot($locked->fresh()), $request);

            return $locked->fresh()->load('product');
        });

        return InventoryResource::make($inventory);
    }

    public function adjustments(Request $request, string $productId): JsonResponse
    {
        $data = $request->validate(['perPage' => ['nullable', 'integer', 'between:1,100']]);
        $product = Product::query()->wherePublicId($productId)->first();
        if ($product === null) {
            throw ProblemException::notFound('No such product.');
        }
        $page = InventoryAdjustment::query()->with(['product', 'createdBy'])
            ->where('product_id', $product->id)->orderByDesc('id')->paginate($data['perPage'] ?? 50);

        return response()->json(['data' => InventoryAdjustmentResource::collection($page->items()), 'page' => $this->page($page)]);
    }

    public function slots(Request $request): JsonResponse
    {
        $data = $request->validate(['perPage' => ['nullable', 'integer', 'between:1,100'], 'active' => ['nullable', 'boolean'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $query = DeliverySlot::query()->orderBy('starts_at');
        if (array_key_exists('active', $data)) {
            $query->where('is_active', $data['active']);
        }
        if (isset($data['from'])) {
            $query->whereDate('slot_date', '>=', $data['from']);
        }
        if (isset($data['to'])) {
            $query->whereDate('slot_date', '<=', $data['to']);
        }
        $page = $query->paginate($data['perPage'] ?? 50);

        return response()->json(['data' => DeliverySlotResource::collection($page->items()), 'page' => $this->page($page)]);
    }

    public function storeSlot(Request $request): JsonResponse
    {
        $attributes = $this->slotAttributes($request);
        $slot = DB::transaction(function () use ($request, $attributes): DeliverySlot {
            $slot = DeliverySlot::create($attributes);
            $this->audit->handle($request->user(), 'delivery_slot.created', $slot, null, $this->slotSnapshot($slot), $request);

            return $slot;
        });

        return DeliverySlotResource::make($slot)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function updateSlot(Request $request, string $slotId): DeliverySlotResource
    {
        $slot = DeliverySlot::wherePublicId($slotId)->first();
        if ($slot === null) {
            throw ProblemException::notFound('No such delivery slot.');
        }
        $attributes = $this->slotAttributes($request, true, $slot);

        DB::transaction(function () use ($request, $slot, $attributes): void {
            $locked = DeliverySlot::query()->lockForUpdate()->findOrFail($slot->id);
            if (($attributes['capacity'] ?? $locked->capacity) < $locked->booked_count) {
                throw ProblemException::slotCapacityBelowBookings($locked->booked_count);
            }
            if ($locked->booked_count > 0 && (isset($attributes['starts_at']) || isset($attributes['ends_at']))) {
                throw ProblemException::slotWindowLocked();
            }
            $before = $this->slotSnapshot($locked);
            $locked->update($attributes);
            $this->audit->handle($request->user(), 'delivery_slot.updated', $locked, $before, $this->slotSnapshot($locked), $request);
        });

        return DeliverySlotResource::make($slot->refresh());
    }

    public function auditEvents(Request $request): JsonResponse
    {
        $data = $request->validate([
            'perPage' => ['nullable', 'integer', 'between:1,100'],
            'action' => ['nullable', 'string', 'max:80'],
            'actorId' => ['nullable', 'uuid'],
            'entityType' => ['nullable', 'string', 'max:80'],
            'entityId' => ['nullable', 'uuid'],
        ]);
        $query = AdminAuditEvent::query()->with('actor')->orderByDesc('id');
        foreach (['action' => 'action', 'entityType' => 'entity_type'] as $input => $column) {
            if (isset($data[$input])) {
                $query->where($column, $data[$input]);
            }
        }
        if (isset($data['actorId'])) {
            $actor = User::wherePublicId($data['actorId'])->first();
            $query->where('actor_id', $actor?->id ?? 0);
        }
        if (isset($data['entityId'])) {
            $binary = Product::encodePublicId($data['entityId']);
            $query->where('entity_public_id', $binary ?? '');
        }
        $page = $query->paginate($data['perPage'] ?? 50);

        return response()->json(['data' => AdminAuditEventResource::collection($page->items()), 'page' => $this->page($page)]);
    }

    /** @return array<string, mixed> */
    private function slotAttributes(Request $request, bool $partial = false, ?DeliverySlot $current = null): array
    {
        $rules = [
            'startsAt' => [$partial ? 'sometimes' : 'required', 'date'],
            'endsAt' => [$partial ? 'sometimes' : 'required', 'date'],
            'capacity' => [$partial ? 'sometimes' : 'required', 'integer', 'min:1'],
            'fee' => [$partial ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'isActive' => [$partial ? 'sometimes' : 'boolean'],
        ];
        $data = $request->validate($rules);
        $starts = isset($data['startsAt']) ? CarbonImmutable::parse($data['startsAt']) : $current?->starts_at;
        $ends = isset($data['endsAt']) ? CarbonImmutable::parse($data['endsAt']) : $current?->ends_at;
        if ($starts === null || $ends === null || ! $ends->isAfter($starts)) {
            throw ValidationException::withMessages(['endsAt' => 'endsAt must be later than startsAt.']);
        }

        $attributes = [];
        if (isset($data['startsAt'])) {
            $attributes['starts_at'] = $starts;
            $attributes['slot_date'] = $starts->toDateString();
        }
        if (isset($data['endsAt'])) {
            $attributes['ends_at'] = $ends;
        }
        foreach (['capacity' => 'capacity', 'fee' => 'fee', 'isActive' => 'is_active'] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $attributes[$column] = $data[$input];
            }
        }

        return $attributes;
    }

    /** @return array<string, mixed> */
    private function inventorySnapshot(Inventory $inventory): array
    {
        return ['productId' => $inventory->product?->public_id, 'onHand' => $inventory->quantity_on_hand, 'reserved' => $inventory->quantity_reserved, 'available' => $inventory->quantity_available];
    }

    /** @return array<string, mixed> */
    private function slotSnapshot(DeliverySlot $slot): array
    {
        return ['slotId' => $slot->public_id, 'startsAt' => $slot->starts_at?->toIso8601String(), 'endsAt' => $slot->ends_at?->toIso8601String(), 'capacity' => $slot->capacity, 'bookedCount' => $slot->booked_count, 'isActive' => $slot->is_active];
    }

    /** @param LengthAwarePaginator<mixed> $page @return array<string, int> */
    private function page($page): array
    {
        return ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()];
    }
}
