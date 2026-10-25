<?php

namespace App\Http\Requests\Todo;

use App\Http\Requests\Concerns\TrimsTitle;
use App\Models\Todo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTodoRequest extends FormRequest
{
    use TrimsTitle;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'min:1', 'max:200'],
            'category' => ['sometimes', 'required', 'string', Rule::in(Todo::CATEGORIES)],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
