<?php

namespace Database\Factories;

use App\Enums\PaymentAttemptStatus;
use App\Models\Order;
use App\Models\PaymentAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PaymentAttempt> */
class PaymentAttemptFactory extends Factory
{
    protected $model = PaymentAttempt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'provider' => 'bakong',
            'status' => PaymentAttemptStatus::Pending,
            'provider_reference' => md5(Str::uuid()->toString()),
            'khqr_payload' => '0002010102126304FFFF',
            'amount' => '10.00',
            'currency' => 'USD',
            'expires_at' => now()->addMinutes(20),
        ];
    }
}
