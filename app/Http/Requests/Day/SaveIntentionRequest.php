<?php

namespace App\Http\Requests\Day;

use App\Models\DailyIntention;

class SaveIntentionRequest extends IntentionDateRequest
{
    /**
     * `text` must be sent; an empty string (read as null by the framework) removes the intention.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'text' => ['present', 'nullable', 'string', 'max:'.DailyIntention::MAX_TEXT_LENGTH],
        ];
    }

    public function text(): ?string
    {
        $text = $this->validated('text');

        return $text === null || trim($text) === '' ? null : trim($text);
    }
}
