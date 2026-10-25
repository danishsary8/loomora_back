<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

class DeleteMeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
        ];
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

                $user = $this->user();

                // Google-only accounts have no password; their re-auth comes with B-015.
                if ($user->password === null) {
                    $validator->errors()->add('password', __('account.google_reauth_required'));
                } elseif (! Hash::check($this->input('password'), $user->password)) {
                    $validator->errors()->add('password', __('account.wrong_password'));
                }
            },
        ];
    }
}
