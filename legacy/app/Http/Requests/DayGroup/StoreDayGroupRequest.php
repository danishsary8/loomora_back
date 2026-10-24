<?php

declare(strict_types=1);

namespace App\Http\Requests\DayGroup;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDayGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:80'], 'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'], 'weekdays' => ['required', 'array', 'min:1'], 'weekdays.*' => ['integer', 'between:0,6', 'distinct']];
    }
}
