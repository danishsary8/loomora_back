<?php

declare(strict_types=1);

namespace App\Http\Requests\Schedule;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

final class ShowTodayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `date` is optional. The client may pass its own local calendar date so the
     * resolved weekday is correct regardless of the server's timezone.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Client-supplied date when present, otherwise the server's configured
     * timezone (see APP_TIMEZONE).
     */
    public function resolveDate(): Carbon
    {
        $date = $this->validated('date');

        return $date !== null
            ? Carbon::createFromFormat('Y-m-d', $date)->startOfDay()
            : Carbon::now();
    }

    public function usedClientDate(): bool
    {
        return $this->validated('date') !== null;
    }
}
