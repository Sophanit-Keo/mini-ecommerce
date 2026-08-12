<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCheckoutQuoteRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'addressId' => ['required', 'uuid'],
            'deliverySlotId' => ['required', 'uuid'],
            'paymentMethod' => ['required', Rule::enum(PaymentMethod::class)],
        ];
    }

    public function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::from((string) $this->input('paymentMethod'));
    }
}
