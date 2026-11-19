<?php

namespace App\Http\Requests\Todo;

use App\Http\Requests\Concerns\TrimsTitle;
use App\Models\Todo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateTodoRequest extends FormRequest
{
    use TodoRules, TrimsTitle;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'min:1', 'max:200'],
            ...$this->optionalFieldRules(),
        ];
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var Todo $todo */
                $todo = $this->route('todo');
                if ($todo->isSubtask() && $this->filled('due_date')) {
                    $validator->errors()->add('due_date', __('validation.subtask_due_date'));
                }
            },
        ];
    }
}
