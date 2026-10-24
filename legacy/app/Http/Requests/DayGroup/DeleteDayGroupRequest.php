<?php

declare(strict_types=1);

namespace App\Http\Requests\DayGroup;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DeleteDayGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->query->has('confirm')) {
            $this->merge(['confirm' => $this->query('confirm')]);
        }
    }

    public function rules(): array
    {
        return ['confirm' => ['required', Rule::in(['true', '1', 1, true])]];
    }
}
