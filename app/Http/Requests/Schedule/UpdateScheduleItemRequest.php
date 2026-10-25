<?php

namespace App\Http\Requests\Schedule;

use App\Models\ScheduleItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateScheduleItemRequest extends FormRequest
{
    use ScheduleItemRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->fieldRules();

        foreach (['title', 'category', 'start_time', 'end_time', 'days', 'starts_on'] as $field) {
            array_unshift($rules[$field], 'sometimes', 'required');
        }
        array_unshift($rules['ends_on'], 'sometimes');

        return $rules;
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var ScheduleItem $item */
                $item = $this->route('item');

                $this->checkTimesAndDates($validator, [
                    'start_time' => $item->start_time,
                    'end_time' => $item->end_time,
                    'starts_on' => $item->starts_on->toDateString(),
                    'ends_on' => $item->ends_on?->toDateString(),
                    ...$this->validated(),
                ]);
            },
        ];
    }
}
