<?php

namespace App\Http\Requests;

use App\Models\Note;
use Illuminate\Foundation\Http\FormRequest;

class NoteRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return $this->isMethod('POST')
            ? ['id' => ['required', 'uuid'], ...self::fields()]
            : self::fields();
    }

    /** @return array<string, list<string>> */
    public static function fields(string $prefix = ''): array
    {
        return [
            $prefix.'title' => ['nullable', 'string', 'max:'.Note::MAX_TITLE_LENGTH],
            $prefix.'body' => ['nullable', 'string', 'max:'.Note::MAX_BODY_LENGTH],
            $prefix.'schedule_item_id' => ['nullable', 'uuid'],
            $prefix.'local_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
