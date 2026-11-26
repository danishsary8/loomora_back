<?php

namespace App\Services;

use App\Models\Note;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class NoteService
{
    /** @param array<string, mixed> $attributes */
    public function create(User $user, array $attributes): Note
    {
        return DB::transaction(function () use ($user, $attributes) {
            [$note, $created] = $this->insert($user, $attributes, '');
            if (! $created) {
                throw new ConflictHttpException(__('notes.conflict'));
            }

            return $note;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $user, string $id, array $attributes): Note
    {
        return DB::transaction(function () use ($user, $id, $attributes) {
            $note = $user->notes()->lockForUpdate()->findOrFail($id);
            $note->fill($attributes);
            if (! array_key_exists('local_date', $attributes)
                && ($note->schedule_item_id === null || $note->isDirty('schedule_item_id'))) {
                $note->local_date = null;
            }
            $this->validate($user, $note, '');
            $note->save();

            return $note;
        });
    }

    /**
     * Called after schedule items are imported, inside the import transaction. A skipped row is
     * never written; its diagnostic only says how the account copy compares with the guest copy.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{created: int, skipped: int, skipped_details: list<array{id: string, status: string}>}
     */
    public function import(User $user, array $rows): array
    {
        $details = [];
        foreach ($rows as $index => $attributes) {
            [$stored, $inserted] = $this->insert($user, $attributes, "notes.{$index}.");
            if (! $inserted) {
                $details[] = ['id' => $stored->id, 'status' => $this->skipStatus($stored, $attributes)];
            }
        }

        return [
            'created' => count($rows) - count($details),
            'skipped' => count($details),
            'skipped_details' => $details,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function skipStatus(Note $stored, array $attributes): string
    {
        if ($stored->trashed()) {
            return Note::IMPORT_DELETED;
        }

        return $this->visibleFields($stored->getAttributes()) === $this->visibleFields($attributes)
            ? Note::IMPORT_IDENTICAL
            : Note::IMPORT_DIFFERENT;
    }

    /**
     * The fields the API shows: an occurrence date is hidden without a block (NoteResource).
     *
     * @param  array<string, mixed>  $fields
     * @return list<string|null>
     */
    private function visibleFields(array $fields): array
    {
        $block = isset($fields['schedule_item_id']) ? strtolower($fields['schedule_item_id']) : null;

        return [
            $fields['title'] ?? null,
            $fields['body'] ?? null,
            $block,
            $block === null ? null : ($fields['local_date'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{Note, bool}
     */
    private function insert(User $user, array $attributes, string $prefix): array
    {
        $id = strtolower($attributes['id']);
        $stored = Note::withTrashed()->find($id);
        if ($stored !== null) {
            $this->assertOwner($user, $stored, $prefix);

            return [$stored, false];
        }

        $note = new Note($attributes);
        $note->id = $id;
        $note->user_id = $user->id;
        $this->validate($user, $note, $prefix);
        foreach (['created_at', 'updated_at'] as $field) {
            if (isset($attributes[$field])) {
                $note->{$field} = Carbon::parse($attributes[$field])->utc();
            }
        }
        $note->updateTimestamps();
        $created = Note::query()->insertOrIgnore($note->getAttributes()) === 1;
        $stored = Note::withTrashed()->findOrFail($id);
        $this->assertOwner($user, $stored, $prefix);

        return [$stored, $created];
    }

    private function assertOwner(User $user, Note $note, string $prefix): void
    {
        if ($note->user_id === $user->id) {
            return;
        }
        if ($prefix === '') {
            throw new ConflictHttpException(__('notes.conflict'));
        }

        throw ValidationException::withMessages([$prefix.'id' => __('notes.conflict')]);
    }

    private function validate(User $user, Note $note, string $prefix): void
    {
        if ($note->title === null && $note->body === null) {
            throw ValidationException::withMessages([$prefix.'body' => __('notes.content_required')]);
        }
        if ($note->schedule_item_id === null && $note->local_date !== null) {
            throw ValidationException::withMessages([$prefix.'local_date' => __('notes.occurrence_requires_block')]);
        }
        if ($note->schedule_item_id !== null
            && (! $note->exists || $note->isDirty('schedule_item_id'))
            && $user->scheduleItems()->whereKey($note->schedule_item_id)->sharedLock()->value('id') === null) {
            throw ValidationException::withMessages([$prefix.'schedule_item_id' => __('notes.block_not_found')]);
        }
    }
}
