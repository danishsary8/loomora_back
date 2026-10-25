<?php

namespace App\Http\Requests\Schedule;

use Illuminate\Foundation\Http\FormRequest;

class CopyDayRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from_weekday' => ['required', 'integer', 'between:0,6'],
            'to_weekdays' => ['required', 'array', 'min:1', 'max:6'],
            'to_weekdays.*' => ['integer', 'distinct', 'between:0,6', 'different:from_weekday'],
        ];
    }
}
