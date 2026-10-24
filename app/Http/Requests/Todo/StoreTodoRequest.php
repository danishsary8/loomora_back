<?php

namespace App\Http\Requests\Todo;

use App\Models\Todo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTodoRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:1', 'max:200'],
            'category' => ['sometimes', 'string', Rule::in(Todo::CATEGORIES)],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->user()->todos()->count() >= Todo::MAX_PER_USER) {
                    $validator->errors()->add('title', __('validation.todo_limit', ['max' => Todo::MAX_PER_USER]));
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->input('title'))]);
        }
    }
}
