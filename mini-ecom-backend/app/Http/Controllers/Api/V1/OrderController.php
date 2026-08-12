<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Orders\PlaceOrder;
use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelOrderRequest;
use App\Http\Requests\PlaceOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\DeliverySlot;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Every lookup is scoped through the authenticated user's own orders — never fetch-then-check
 * — following the same IDOR discipline as AddressController.
 */
class OrderController extends Controller
{
    /**
     * Statuses a customer may still cancel from. Anything past `confirmed` is already being
     * picked or is on its way, so the store — not the customer — has to intervene.
     */
    private const CANCELLABLE_STATUSES = [OrderStatus::PendingPayment, OrderStatus::Confirmed];

    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function __construct(private readonly PlaceOrder $placeOrder) {}

    public function index(Request $request): JsonResponse
    {
        // `paginate()` performs both an OFFSET read and a COUNT(*). A caller-controlled
        // million-row page is therefore a database and memory denial-of-service, not a useful
        // client feature. Keep the documented default, and cap a valid request at 100 rows.
        $perPage = min(max((int) $request->query('perPage', self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);

        $orders = $request->user()->orders()
            ->orderByDesc('placed_at')
            ->paginate($perPage);

        return response()->json([
            'data' => OrderResource::collection($orders->items()),
            'page' => [
                'currentPage' => $orders->currentPage(),
                'lastPage' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function show(Request $request, string $orderId): OrderResource
    {
        return OrderResource::make(
            $this->findForUser($request, $orderId, ['items', 'statusHistory' => fn ($query) => $query->orderBy('created_at')])
        );
    }

    public function store(PlaceOrderRequest $request): JsonResponse
    {
        $user = $request->user();
        $idempotencyKey = (string) $request->input('idempotencyKey');

        // A replayed idempotency key returns the order already created rather than
        // re-validating a cart/slot that checkout has since consumed.
        $existing = Order::where('user_id', $user->id)->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return OrderResource::make($existing->load(['items', 'statusHistory']))
                ->response()
                ->setStatusCode(Response::HTTP_CREATED);
        }

        $address = $user->addresses()->wherePublicId($request->string('addressId')->toString())->first();

        if ($address === null) {
            throw ProblemException::notFound('No such address.');
        }

        $slot = DeliverySlot::wherePublicId($request->string('deliverySlotId')->toString())->first();

        if ($slot === null || ! $slot->is_active) {
            throw ProblemException::slotUnavailable($request->string('deliverySlotId')->toString());
        }

        if ($slot->isFull()) {
            throw ProblemException::slotUnavailable($slot->public_id);
        }

        $cart = $user->carts()->where('status', CartStatus::Active)->with('items.product.inventory')->first();

        if ($cart === null || $cart->items->isEmpty()) {
            throw ProblemException::badRequest('The cart is empty.');
        }

        $order = $this->placeOrder->handle(
            user: $user,
            cart: $cart,
            address: $address,
            slot: $slot,
            paymentMethod: $request->paymentMethod(),
            idempotencyKey: $idempotencyKey,
            customerNote: $request->input('customerNote'),
        );

        return OrderResource::make($order->load(['items', 'statusHistory']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function cancel(CancelOrderRequest $request, string $orderId): OrderResource
    {
        $order = $this->findForUser($request, $orderId);

        if (! in_array($order->status, self::CANCELLABLE_STATUSES, true)) {
            throw ProblemException::invalidStatusTransition($order->status->value, OrderStatus::Cancelled->value);
        }

        $fromStatus = $order->status;

        DB::transaction(function () use ($request, $order, $fromStatus) {
            $order->update([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $request->string('cancellationReason')->toString(),
            ]);

            $order->statusHistory()->create([
                'from_status' => $fromStatus,
                'to_status' => OrderStatus::Cancelled,
                'changed_by' => $request->user()->id,
                'note' => $request->string('cancellationReason')->toString(),
            ]);
        });

        return OrderResource::make($order->refresh()->load(['items', 'statusHistory']));
    }

    /**
     * @param  array<int, mixed>  $with
     */
    private function findForUser(Request $request, string $orderId, array $with = []): Order
    {
        $order = $request->user()->orders()->with($with)->wherePublicId($orderId)->first();

        if ($order === null) {
            throw ProblemException::notFound('No such order.');
        }

        return $order;
    }
}
