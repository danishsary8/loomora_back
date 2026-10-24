<?php

declare(strict_types=1);

namespace App\Http\Requests\ChecklistItem;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateChecklistItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['label' => ['sometimes', 'string', 'max:160'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535']];
    }
}
