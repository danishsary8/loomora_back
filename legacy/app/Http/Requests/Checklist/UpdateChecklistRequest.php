<?php

declare(strict_types=1);

namespace App\Http\Requests\Checklist;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateChecklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The {date} route segment is validated here too, so a malformed date is a
     * clean 422 rather than an exception deeper in the stack.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'checked_ids' => ['present', 'array'],
            'checked_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return array_merge($this->all(), [
            'date' => $this->route('date'),
        ]);
    }
}
