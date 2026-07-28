<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Mirrors the `AddressRequest` schema. PATCH reuses it with every field optional, since the
 * spec sends the same shape to both.
 */
class StoreAddressRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'label' => ['nullable', 'string', 'max:40'],
            'recipientName' => [$required, 'string', 'max:120'],
            'phone' => [$required, 'string', 'max:32'],
            'line1' => [$required, 'string', 'max:180'],
            'line2' => ['nullable', 'string', 'max:180'],
            'city' => [$required, 'string', 'max:80'],
            'region' => ['nullable', 'string', 'max:80'],
            'postalCode' => ['nullable', 'string', 'max:20'],
            'countryCode' => [$required, 'string', 'size:2'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'deliveryNotes' => ['nullable', 'string', 'max:500'],
            'isDefault' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * `ck_addresses_geo_pair` rejects half a coordinate at the database. Catching it here
     * turns a 500 into a field-level validation message.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $this->has(['latitude', 'longitude'])) {
                    return;
                }

                if (($this->input('latitude') === null) !== ($this->input('longitude') === null)) {
                    $validator->errors()->add(
                        'latitude',
                        'Latitude and longitude must be provided together or not at all.',
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        $map = [
            'label' => 'label',
            'recipientName' => 'recipient_name',
            'phone' => 'phone',
            'line1' => 'line1',
            'line2' => 'line2',
            'city' => 'city',
            'region' => 'region',
            'postalCode' => 'postal_code',
            'countryCode' => 'country_code',
            'latitude' => 'latitude',
            'longitude' => 'longitude',
            'deliveryNotes' => 'delivery_notes',
            'isDefault' => 'is_default',
        ];

        $attributes = [];

        foreach ($map as $input => $column) {
            if ($this->has($input)) {
                $attributes[$column] = $this->input($input);
            }
        }

        if (isset($attributes['country_code'])) {
            $attributes['country_code'] = strtoupper($attributes['country_code']);
        }

        return $attributes;
    }
}
