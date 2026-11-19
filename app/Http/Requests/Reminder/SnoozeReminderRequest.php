<?php

namespace App\Http\Requests\Reminder;

use App\Models\Todo;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SnoozeReminderRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('remind', $this->todo());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'minutes' => ['required', 'integer', Rule::in(Todo::SNOOZE_MINUTES)],
        ];
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->todo()->isSubtask()) {
                    $validator->errors()->add('remind_at', __('validation.subtask_reminder'));
                }
            },
        ];
    }

    public function todo(): Todo
    {
        /** @var Todo */
        return $this->route('todo');
    }
}
