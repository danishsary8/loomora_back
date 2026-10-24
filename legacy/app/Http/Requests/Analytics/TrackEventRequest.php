<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use App\Services\AnalyticsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TrackEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['event' => ['required', Rule::in(AnalyticsService::CLIENT_EVENTS)], 'idempotency_key' => ['required', 'uuid']];
    }
}
