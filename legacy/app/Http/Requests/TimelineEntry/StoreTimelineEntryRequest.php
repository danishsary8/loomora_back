<?php

declare(strict_types=1);

namespace App\Http\Requests\TimelineEntry;

use App\Enums\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTimelineEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i'], 'description' => ['required', 'string', 'max:180'], 'category' => ['required', Rule::enum(Category::class)->except(Category::retired())], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535']];
    }
}
