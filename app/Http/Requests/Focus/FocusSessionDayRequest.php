<?php

namespace App\Http\Requests\Focus;

use Illuminate\Foundation\Http\FormRequest;

/** `?date=` for one local day of Focus sessions. */
class FocusSessionDayRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
