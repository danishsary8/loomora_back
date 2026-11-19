<?php

namespace App\Http\Requests\Todo;

use App\Http\Requests\Concerns\TrimsTitle;
use App\Models\Todo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTodoRequest extends FormRequest
{
    use TodoRules, TrimsTitle;

    private ?Todo $parent = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:1', 'max:200'],
            ...$this->optionalFieldRules(),
            'parent_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->user()->todos()->count() >= Todo::MAX_PER_USER) {
                    $validator->errors()->add('title', __('validation.todo_limit', ['max' => Todo::MAX_PER_USER]));
                }
            },
            function (Validator $validator) {
                if ($validator->errors()->has('parent_id')) {
                    return;
                }
                $this->checkRepeat($validator, null, $this->parent() !== null);
                $this->checkReminder($validator, $this->parent() !== null);
                if ($this->parent() === null) {
                    return;
                }
                if ($this->parent()->isSubtask()) {
                    $validator->errors()->add('parent_id', __('validation.subtask_depth'));
                }
                if ($this->filled('due_date')) {
                    $validator->errors()->add('due_date', __('validation.subtask_due_date'));
                }
            },
        ];
    }

    /** The parent to-do, or null for a top-level one. Another user's id is a 404. */
    public function parent(): ?Todo
    {
        if (! $this->filled('parent_id')) {
            return null;
        }

        return $this->parent ??= $this->user()->todos()->findOrFail($this->input('parent_id'));
    }
}
