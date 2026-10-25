<?php

namespace App\Http\Requests\Schedule;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ScheduleNowRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
        ];
    }

    /** The client's "today" must be within a day of the user's own time zone (docs/09). */
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

                $today = CarbonImmutable::parse(now($this->user()->timezone)->toDateString());

                if (abs($today->diffInDays(CarbonImmutable::parse($this->input('date')), false)) > 1) {
                    $validator->errors()->add('date', __('schedule.date_out_of_range'));
                }
            },
        ];
    }
}
