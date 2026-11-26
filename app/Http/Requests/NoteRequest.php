<?php

namespace App\Http\Requests;

use App\Models\Note;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;

class NoteRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return $this->isMethod('POST')
            ? ['id' => ['required', 'uuid'], ...self::fields()]
            : [...self::fields(), ...self::expectedRevisionRules()];
    }

    /** @return array<string, list<string>> */
    public static function fields(string $prefix = ''): array
    {
        return [
            $prefix.'title' => ['nullable', 'string', 'max:'.Note::MAX_TITLE_LENGTH],
            $prefix.'body' => ['nullable', 'string', 'max:'.Note::MAX_BODY_LENGTH],
            $prefix.'schedule_item_id' => ['nullable', 'uuid'],
            $prefix.'local_date' => ['nullable', 'date_format:Y-m-d'],
            $prefix.'pinned' => ['sometimes', 'boolean'],
            $prefix.'color' => ['nullable', 'string', 'in:'.implode(',', Note::COLORS)],
            $prefix.'tags' => ['nullable', 'array', 'list', 'max:'.Note::MAX_TAGS],
            $prefix.'tags.*' => ['string', 'regex:/\S/', 'max:'.Note::MAX_TAG_LENGTH],
        ];
    }

    /**
     * The optional B-053 precondition: a body field on PATCH, a query parameter on DELETE.
     *
     * @return array<string, list<string>>
     */
    public static function expectedRevisionRules(): array
    {
        return ['expected_revision' => ['sometimes', 'integer', 'min:1']];
    }

    /** Call only after `expectedRevisionRules()` passed; null when the client sent none. */
    public static function expectedRevision(Request $request): ?int
    {
        return $request->has('expected_revision') ? $request->integer('expected_revision') : null;
    }

    /** @return array<string, mixed> the note fields, without the precondition */
    public function noteFields(): array
    {
        return $this->safe()->except('expected_revision');
    }
}
