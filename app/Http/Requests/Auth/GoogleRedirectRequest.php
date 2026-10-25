<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GoogleRedirectRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'intent' => ['required', Rule::in(['sign_in', 'sign_up'])],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
        ];
    }
}
