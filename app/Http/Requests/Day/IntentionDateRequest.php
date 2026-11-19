<?php

namespace App\Http\Requests\Day;

use App\Models\DailyIntention;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** The `{date}` of a day route: Y-m-d, at most 366 days from the user's own today. */
class IntentionDateRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return [...$this->all(), 'date' => $this->route('date')];
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('date')) {
                    return;
                }

                $today = CarbonImmutable::now($this->user()->timezone)->startOfDay();

                if (abs($this->localDate()->diffInDays($today, false)) > DailyIntention::MAX_DAYS_FROM_TODAY) {
                    $validator->errors()->add('date', __('validation.intention_date_out_of_range', ['days' => DailyIntention::MAX_DAYS_FROM_TODAY]));
                }
            },
        ];
    }

    public function localDate(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->route('date'), $this->user()->timezone);
    }
}
