<?php

namespace App\Http\Requests\Schedule;

use App\Models\ScheduleItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreScheduleItemRequest extends FormRequest
{
    use ScheduleItemRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->fieldRules();

        foreach (['title', 'start_time', 'end_time', 'days'] as $field) {
            array_unshift($rules[$field], 'required');
        }
        array_unshift($rules['category'], 'sometimes');
        array_unshift($rules['starts_on'], 'sometimes');

        return $rules;
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->checkTimesAndDates($validator, [
                    ...$this->only(['start_time', 'end_time', 'ends_on']),
                    'starts_on' => $this->startsOn(),
                ]);

                if ($this->user()->scheduleItems()->count() >= ScheduleItem::MAX_PER_USER) {
                    $validator->errors()->add('title', __('schedule.limit', ['max' => ScheduleItem::MAX_PER_USER]));
                }
            },
        ];
    }

    /** Defaults to today in the user's time zone. */
    public function startsOn(): string
    {
        return $this->input('starts_on') ?? now($this->user()->timezone)->toDateString();
    }
}
