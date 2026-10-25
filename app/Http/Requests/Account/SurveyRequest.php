<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SurveyRequest extends FormRequest
{
    public const GOALS = ['be_consistent', 'manage_time', 'finish_tasks', 'other'];

    /**
     * Both answers are optional: an empty submit means "skip" (docs/07 S8).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'goal' => ['nullable', 'string', Rule::in(self::GOALS)],
            'heard_from' => ['nullable', 'string', 'max:500'],
        ];
    }
}
