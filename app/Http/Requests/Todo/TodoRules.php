<?php

namespace App\Http\Requests\Todo;

use App\Models\Todo;
use Illuminate\Validation\Rule;

/** Field rules shared by creating and editing a to-do. */
trait TodoRules
{
    /**
     * @return array<string, mixed>
     */
    protected function optionalFieldRules(): array
    {
        return [
            'category' => ['sometimes', 'required', 'string', Rule::in(Todo::CATEGORIES)],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:'.Todo::MAX_NOTES_LENGTH],
            'priority' => ['sometimes', 'required', 'string', Rule::in(Todo::PRIORITIES)],
        ];
    }
}
