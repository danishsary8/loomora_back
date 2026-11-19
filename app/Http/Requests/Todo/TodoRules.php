<?php

namespace App\Http\Requests\Todo;

use App\Models\Todo;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            ...self::repeatRuleRules('repeat_rule'),
            'remind_at' => ['sometimes', ...self::dateTimeRules()],
        ];
    }

    /**
     * A nullable ISO 8601 date-time with an explicit UTC offset (`Z` or `+07:00`), as `toISOString()` sends it.
     *
     * @return list<string>
     */
    public static function dateTimeRules(): array
    {
        return ['nullable', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/'];
    }

    /**
     * Rules for a repeat rule at `$key`: {type: daily} or {type: weekly, days: [0..6]}.
     *
     * @return array<string, mixed>
     */
    public static function repeatRuleRules(string $key): array
    {
        return [
            $key => ['sometimes', 'nullable', 'array:type,days'],
            "{$key}.type" => ['required_with:'.$key, Rule::in([Todo::REPEAT_DAILY, Todo::REPEAT_WEEKLY])],
            "{$key}.days" => ['exclude_unless:'.$key.'.type,'.Todo::REPEAT_WEEKLY, 'required', 'array', 'min:1', 'max:7'],
            "{$key}.days.*" => ['integer', 'distinct', 'between:0,6'],
        ];
    }

    /**
     * A repeating to-do needs a due date, and a subtask cannot repeat. Fields not sent
     * keep the value of `$existing` (when editing).
     */
    protected function checkRepeat(Validator $validator, ?Todo $existing, bool $isSubtask): void
    {
        if ($validator->errors()->hasAny(['repeat_rule', 'repeat_rule.*', 'due_date'])) {
            return;
        }

        $repeats = $this->exists('repeat_rule') ? $this->filled('repeat_rule') : $existing?->repeat_rule !== null;
        $hasDueDate = $this->exists('due_date') ? $this->filled('due_date') : $existing?->due_date !== null;

        if ($isSubtask && $this->filled('repeat_rule')) {
            $validator->errors()->add('repeat_rule', __('validation.subtask_repeat'));
        } elseif ($repeats && ! $hasDueDate) {
            $validator->errors()->add('due_date', __('validation.repeat_needs_due_date'));
        }
    }

    protected function checkReminder(Validator $validator, bool $isSubtask): void
    {
        if ($isSubtask && $this->filled('remind_at')) {
            $validator->errors()->add('remind_at', __('validation.subtask_reminder'));
        }
    }
}
