<?php

namespace App\Services;

use App\Models\Note;
use App\Models\ScheduleItem;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Every account note write. The server owns `revision` (B-053): it starts at 1 and moves by one on
 * each real change, so a client's `expected_revision` can reject a stale edit or delete.
 */
class NoteService
{
    /**
     * An identical replay of an active note returns it unchanged; any other existing UUID is a conflict,
     * and a deleted note is never restored by a create.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{Note, bool} the note and whether it was created
     */
    public function create(User $user, array $attributes): array
    {
        return DB::transaction(function () use ($user, $attributes) {
            [$note, $created] = $this->insert($user, $attributes, '');
            if (! $created && ($note->trashed() || ! $this->sameVisibleFields($note, $attributes))) {
                throw new ConflictHttpException(__('notes.conflict'));
            }

            return [$note, $created];
        });
    }

    /**
     * A null `$expectedRevision` keeps last-write-wins. An edit that changes nothing keeps the revision.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, string $id, array $attributes, ?int $expectedRevision): Note
    {
        return DB::transaction(function () use ($user, $id, $attributes, $expectedRevision) {
            $note = $user->notes()->lockForUpdate()->findOrFail($id);
            $this->assertRevision($note, $expectedRevision);
            $note->fill($attributes);
            if (! array_key_exists('local_date', $attributes)
                && ($note->schedule_item_id === null || $note->isDirty('schedule_item_id'))) {
                $note->local_date = null;
            }
            if (! $note->isDirty()) {
                return $note;
            }
            $this->validate($user, $note, '');
            $note->revision++;
            $note->save();

            return $note;
        });
    }

    /** Deleting a deleted note succeeds again without a revision check, as before B-053. */
    public function delete(User $user, string $id, ?int $expectedRevision): void
    {
        DB::transaction(function () use ($user, $id, $expectedRevision) {
            $note = $user->notes()->withTrashed()->lockForUpdate()->findOrFail($id);
            if ($note->trashed()) {
                return;
            }
            $this->assertRevision($note, $expectedRevision);
            $note->forceFill(['revision' => $note->revision + 1, 'deleted_at' => $note->freshTimestamp()])->save();
        });
    }

    /** Restoring an active note returns it unchanged. */
    public function restore(User $user, string $id): Note
    {
        return DB::transaction(function () use ($user, $id) {
            $note = $user->notes()->withTrashed()->lockForUpdate()->findOrFail($id);
            if ($note->deleted_at !== null) {
                abort_unless($note->deleted_at->gt(now()->subDays(Note::RESTORE_WITHIN_DAYS)),
                    Response::HTTP_GONE, __('notes.restore_expired'));
                $note->revision++;
                $note->restore();
            }

            return $note;
        });
    }

    /**
     * Call inside the schedule item's delete transaction: its notes, deleted ones included, become
     * standalone with a new revision. The foreign key's set-null stays as a backstop.
     */
    public function detachFromBlock(ScheduleItem $item): void
    {
        Note::withTrashed()->where('schedule_item_id', $item->id)->update([
            'schedule_item_id' => null,
            'local_date' => null,
            'revision' => DB::raw('revision + 1'),
        ]);
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

    private function assertRevision(Note $note, ?int $expectedRevision): void
    {
        if ($expectedRevision !== null && $expectedRevision !== $note->revision) {
            throw new ConflictHttpException(__('notes.stale_revision'));
        }
    }

    /** @param array<string, mixed> $attributes */
    private function skipStatus(Note $stored, array $attributes): string
    {
        if ($stored->trashed()) {
            return Note::IMPORT_DELETED;
        }

        return $this->sameVisibleFields($stored, $attributes) ? Note::IMPORT_IDENTICAL : Note::IMPORT_DIFFERENT;
    }

    /** @param array<string, mixed> $attributes */
    private function sameVisibleFields(Note $stored, array $attributes): bool
    {
        return $this->visibleFields($stored->getAttributes()) === $this->visibleFields($attributes);
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
