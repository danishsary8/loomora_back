<?php

namespace App\Http\Requests\Schedule;

use App\Models\ScheduleItem;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Field rules shared by create and edit. */
trait ScheduleItemRules
{
    /**
     * @return array<string, mixed>
     */
    protected function fieldRules(): array
    {
        return [
            'title' => ['string', 'min:1', 'max:200'],
            'category' => ['string', Rule::in(ScheduleItem::CATEGORIES)],
            'start_time' => ['date_format:H:i'],
            'end_time' => ['date_format:H:i'],
            'days' => ['array', 'min:1', 'max:7'],
            'days.*' => ['integer', 'distinct', 'between:0,6'],
            'starts_on' => ['date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->input('title'))]);
        }
    }

    /**
     * Cross-field checks against the final values (request merged over the stored item).
     *
     * @param  array<string, mixed>  $final
     */
    protected function checkTimesAndDates(Validator $validator, array $final): void
    {
        if (isset($final['start_time'], $final['end_time']) && ScheduleItem::hhmm($final['start_time']) >= ScheduleItem::hhmm($final['end_time'])) {
            $validator->errors()->add('end_time', __('schedule.end_after_start'));
        }

        if (! empty($final['ends_on']) && isset($final['starts_on']) && $final['ends_on'] < $final['starts_on']) {
            $validator->errors()->add('ends_on', __('schedule.ends_after_starts'));
        }
    }
}
