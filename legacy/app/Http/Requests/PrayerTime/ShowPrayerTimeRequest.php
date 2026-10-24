<?php

declare(strict_types=1);

namespace App\Http\Requests\PrayerTime;

use Illuminate\Foundation\Http\FormRequest;

final class ShowPrayerTimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'lat' => ['required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['required_with:lat', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return array_merge($this->query(), [
            'date' => $this->route('date'),
        ]);
    }

    public function latitude(): float
    {
        return (float) ($this->validated('lat') ?? config('services.aladhan.default_latitude'));
    }

    public function longitude(): float
    {
        return (float) ($this->validated('lng') ?? config('services.aladhan.default_longitude'));
    }
}
