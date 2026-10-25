<?php

namespace App\Http\Requests\Concerns;

/** Emails are stored lower-case, so compare them that way too. */
trait NormalizesEmail
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }
}
