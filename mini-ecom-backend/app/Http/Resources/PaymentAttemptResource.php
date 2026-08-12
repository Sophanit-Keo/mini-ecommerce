<?php

namespace App\Http\Resources;

use App\Enums\PaymentAttemptStatus;
use App\Models\PaymentAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PaymentAttempt */
class PaymentAttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'provider' => $this->provider,
            'status' => $this->status->value,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'khqrPayload' => $this->when($this->status === PaymentAttemptStatus::Pending, $this->khqr_payload),
            'expiresAt' => $this->expires_at?->toIso8601String(),
            'verifiedAt' => $this->verified_at?->toIso8601String(),
        ];
    }
}
