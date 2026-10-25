<?php

namespace App\Http\Requests\Concerns;

/** A title of only spaces counts as empty, so trim before validating. */
trait TrimsTitle
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->input('title'))]);
        }
    }
}
