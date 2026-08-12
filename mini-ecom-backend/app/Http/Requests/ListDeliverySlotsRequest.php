<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ListDeliverySlotsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Restrict browse windows so a public request cannot turn the endpoint into a full delivery
     * schedule export. A 31-day horizon is long enough for ordinary grocery planning.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = $this->input('from') ? CarbonImmutable::parse($this->input('from')) : now()->startOfDay();
            $to = $this->input('to') ? CarbonImmutable::parse($this->input('to')) : $from->addDays(14);

            if ($to->lessThan($from)) {
                $validator->errors()->add('to', 'The to date must be on or after the from date.');
            }

            if ($to->greaterThan($from->addDays(31))) {
                $validator->errors()->add('to', 'The date range may not exceed 31 days.');
            }
        }];
    }

    public function fromDate(): CarbonImmutable
    {
        return $this->input('from')
            ? CarbonImmutable::parse($this->input('from'))->startOfDay()
            : CarbonImmutable::now()->startOfDay();
    }

    public function toDate(): CarbonImmutable
    {
        return $this->input('to')
            ? CarbonImmutable::parse($this->input('to'))->endOfDay()
            : $this->fromDate()->addDays(14)->endOfDay();
    }
}
