<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StatsSummaryRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'from' => ['bail', 'required', 'date_format:Y-m-d', 'after:0000-12-31'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->query->all();
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $from = CarbonImmutable::parse($this->query('from'));
                if ($from->diffInDays(CarbonImmutable::parse($this->query('to'))) > 91) {
                    $validator->errors()->add('to', __('validation.stats_range'));
                }
            },
        ];
    }
}
