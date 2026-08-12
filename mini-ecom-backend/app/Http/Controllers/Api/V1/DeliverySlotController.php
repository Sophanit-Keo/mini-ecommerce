<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListDeliverySlotsRequest;
use App\Http\Resources\DeliverySlotResource;
use App\Models\DeliverySlot;
use Illuminate\Http\JsonResponse;

class DeliverySlotController extends Controller
{
    /**
     * Return only future, active slots that still have capacity. The order placement action locks
     * and checks the slot again, because this read is a discovery aid, not a reservation.
     */
    public function index(ListDeliverySlotsRequest $request): JsonResponse
    {
        $slots = DeliverySlot::query()
            ->where('is_active', true)
            ->where('starts_at', '>', now())
            ->whereBetween('slot_date', [$request->fromDate()->toDateString(), $request->toDate()->toDateString()])
            ->whereColumn('booked_count', '<', 'capacity')
            ->orderBy('starts_at')
            ->get();

        return response()->json(['data' => DeliverySlotResource::collection($slots)]);
    }
}
