<?php

namespace App\Http\Requests\Schedule;

use App\Models\ScheduleException;
use App\Models\ScheduleItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PutScheduleExceptionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([ScheduleException::SKIP, ScheduleException::OVERRIDE])],
            'title' => ['nullable', 'string', 'min:1', 'max:200'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->input('title'))]);
        }
    }

    /** An override must change something, and the final times must still be start < end. */
    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || $this->input('type') !== ScheduleException::OVERRIDE) {
                    return;
                }

                if (! $this->filled('title') && ! $this->filled('start_time') && ! $this->filled('end_time')) {
                    $validator->errors()->add('type', __('schedule.override_needs_change'));

                    return;
                }

                /** @var ScheduleItem $item */
                $item = $this->route('item');
                $start = $this->input('start_time') ?? ScheduleItem::hhmm($item->start_time);
                $end = $this->input('end_time') ?? ScheduleItem::hhmm($item->end_time);

                if ($start >= $end) {
                    $validator->errors()->add('end_time', __('schedule.end_after_start'));
                }
            },
        ];
    }
}
