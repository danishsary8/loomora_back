<?php

declare(strict_types=1);

namespace App\Http\Requests\NotificationSetting;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateNotificationSettingRequest extends FormRequest
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
            'enabled' => ['sometimes', 'boolean'],
            'minutes_before' => ['sometimes', 'integer', 'min:0', 'max:120'],
        ];
    }
}
