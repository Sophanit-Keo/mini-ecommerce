<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payments\ManageBakongPayment;
use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentAttemptResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BakongPaymentController extends Controller
{
    public function __construct(private readonly ManageBakongPayment $payments) {}

    public function start(Request $request, string $orderId): JsonResponse
    {
        $attempt = $this->payments->start($request->user(), $this->findForUser($request, $orderId));

        return PaymentAttemptResource::make($attempt)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function verify(Request $request, string $orderId): PaymentAttemptResource
    {
        $attempt = $this->payments->verify($request->user(), $this->findForUser($request, $orderId));

        return PaymentAttemptResource::make($attempt);
    }

    private function findForUser(Request $request, string $orderId): Order
    {
        $order = $request->user()->orders()->wherePublicId($orderId)->first();

        if ($order === null) {
            throw ProblemException::notFound('No such order.');
        }

        return $order;
    }
}
