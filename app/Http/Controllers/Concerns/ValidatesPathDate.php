<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Validation\ValidationException;

trait ValidatesPathDate
{
    /** A `{date}` URL segment must be a real calendar date, YYYY-MM-DD. */
    private function assertValidDate(string $date): void
    {
        $parsed = \DateTime::createFromFormat('!Y-m-d', $date);

        if (! $parsed || $parsed->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages(['date' => __('validation.date_format', ['attribute' => 'date', 'format' => 'Y-m-d'])]);
        }
    }
}
