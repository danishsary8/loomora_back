<?php

namespace App\Services;

use App\Models\FocusSession;
use App\Models\ScheduleItem;
use App\Models\Todo;
use App\Models\User;
use App\Support\FocusSessionPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Stores a finished Focus session once per client UUID (B-043).
 *
 * The UUID is the idempotency key: an identical retry returns the stored row, a changed payload or
 * another account's UUID is a 409. The insert ignores a primary-key clash, so two concurrent retries
 * resolve in the database (the loser waits for the winner's commit, then reads its row).
 */
class FocusSessionService
{
    /**
     * @return array{FocusSession, bool} the stored session and whether this call created it
     */
    public function record(User $user, FocusSessionPayload $payload): array
    {
        return DB::transaction(function () use ($user, $payload) {
            $stored = FocusSession::find($payload->id);
            if ($stored !== null) {
                return [$this->replay($user, $stored, $payload), false];
            }

            $this->assertLinksOwned($user, $payload);

            $session = (new FocusSession)->forceFill($payload->attributes($user));
            $session->updateTimestamps();
            $created = FocusSession::query()->insertOrIgnore($session->getAttributes()) === 1;
            $stored = FocusSession::findOrFail($payload->id);

            return [$created ? $stored : $this->replay($user, $stored, $payload), $created];
        });
    }

    private function replay(User $user, FocusSession $stored, FocusSessionPayload $payload): FocusSession
    {
        if ($stored->user_id !== $user->id || ! hash_equals($stored->payload_hash, $payload->fingerprint())) {
            throw new ConflictHttpException(__('focus.conflict'));
        }

        return $stored;
    }

    /** Locks the linked row so it cannot vanish before the insert; a missing or foreign link is a 422. */
    private function assertLinksOwned(User $user, FocusSessionPayload $payload): void
    {
        $links = [
            'todo_id' => [Todo::withTrashed(), $payload->todoId],
            'schedule_item_id' => [ScheduleItem::query(), $payload->scheduleItemId],
        ];

        foreach ($links as $field => [$query, $id]) {
            if ($id !== null && $query->whereKey($id)->where('user_id', $user->id)->sharedLock()->value('id') === null) {
                throw ValidationException::withMessages([$field => __('focus.link_not_found')]);
            }
        }
    }
}
