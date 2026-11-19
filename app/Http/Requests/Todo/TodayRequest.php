<?php

namespace App\Http\Requests\Todo;

use App\Support\LocalDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TodayRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * The client sends its local date; it must be within a day of "today"
     * in the user's own time zone (docs/09, A8-8).
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('date')) {
                    return;
                }

                $userToday = LocalDate::startOfDay($this->user(), LocalDate::todayFor($this->user()));

                if (abs($this->localDate()->diffInDays($userToday, false)) > 1) {
                    $validator->errors()->add('date', __('validation.todo_date_out_of_range'));
                }
            },
        ];
    }

    public function localDate(): CarbonImmutable
    {
        return LocalDate::startOfDay($this->user(), $this->input('date'));
    }
}
