<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Re-authenticates a destructive, irreversible action.
 *
 * Password-backed accounts must supply their current password. Accounts created
 * through Google have a null password (see GoogleAuthService), so there is no
 * credential to verify — those fall back to typing the confirmation word.
 * Without that fallback a Google-only user could never delete their account,
 * which the privacy policy explicitly promises them.
 */
final class DeleteAccountRequest extends FormRequest
{
    public const CONFIRMATION_WORD = 'DELETE';

    public function authorize(): bool
    {
        return true;
    }

    /** Whether the authenticated account can be verified by password. */
    public function requiresPassword(): bool
    {
        /** @var User|null $user */
        $user = $this->user();

        return $user?->password !== null;
    }

    public function rules(): array
    {
        if (! $this->requiresPassword()) {
            return ['confirmation' => ['required', Rule::in([self::CONFIRMATION_WORD])]];
        }

        return [
            /*
             * Verified with Hash::check rather than the `current_password` rule:
             * that rule resolves a guard, and these routes authenticate with
             * Sanctum bearer tokens rather than the default session guard.
             */
            'password' => [
                'required',
                'string',
                function (string $attribute, mixed $value, callable $fail): void {
                    /** @var User|null $user */
                    $user = $this->user();

                    if ($user?->password === null || ! is_string($value) || ! Hash::check($value, $user->password)) {
                        $fail('That password is incorrect.');
                    }
                },
            ],
        ];
    }

    public function attributes(): array
    {
        return ['password' => 'password'];
    }
}
