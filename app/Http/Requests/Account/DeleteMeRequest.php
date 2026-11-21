<?php

namespace App\Http\Requests\Account;

use App\Services\GoogleDeletionProof;
use Illuminate\Foundation\Http\FormRequest;

class DeleteMeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function validationData(): array
    {
        if ($this->attributes->getBoolean('google_deletion')) {
            return $this->json()->all();
        }

        return parent::validationData();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->attributes->getBoolean('google_deletion')) {
            return [
                'password' => ['missing'],
                'google_confirmation' => ['required', 'array:challenge_id,browser_verifier'],
                'google_confirmation.challenge_id' => ['required', 'uuid'],
                'google_confirmation.browser_verifier' => ['required', 'string', GoogleDeletionProof::BROWSER_SECRET],
            ];
        }

        return [
            'password' => ['required', 'string'],
        ];
    }
}
