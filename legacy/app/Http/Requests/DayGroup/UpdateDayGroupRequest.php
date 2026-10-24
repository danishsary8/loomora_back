<?php

declare(strict_types=1);

namespace App\Http\Requests\DayGroup;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateDayGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['sometimes', 'string', 'max:80'], 'color' => ['sometimes', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'], 'weekdays' => ['sometimes', 'array', 'min:1'], 'weekdays.*' => ['integer', 'between:0,6', 'distinct']];
    }
}
