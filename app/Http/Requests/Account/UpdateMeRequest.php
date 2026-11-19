<?php

namespace App\Http\Requests\Account;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:100'],
            'timezone' => ['sometimes', 'required', 'string', 'timezone:all'],
            'time_format' => ['sometimes', 'required', 'string', Rule::in(User::TIME_FORMATS)],
            'week_starts_on' => ['sometimes', 'required', 'integer', 'between:1,7'],
        ];
    }
}
