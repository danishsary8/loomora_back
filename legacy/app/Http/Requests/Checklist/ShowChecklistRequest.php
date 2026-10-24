<?php

declare(strict_types=1);

namespace App\Http\Requests\Checklist;

use Illuminate\Foundation\Http\FormRequest;

final class ShowChecklistRequest extends FormRequest
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return ['date' => $this->route('date')];
    }
}
