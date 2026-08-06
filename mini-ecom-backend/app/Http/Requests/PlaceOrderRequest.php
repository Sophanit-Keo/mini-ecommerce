<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlaceOrderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'addressId' => ['required', 'uuid'],
            'deliverySlotId' => ['required', 'uuid'],
            'paymentMethod' => ['required', Rule::enum(PaymentMethod::class)],
            'customerNote' => ['nullable', 'string', 'max:500'],
            'idempotencyKey' => ['required', 'string', 'max:64'],
        ];
    }

    public function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::from((string) $this->input('paymentMethod'));
    }
}
