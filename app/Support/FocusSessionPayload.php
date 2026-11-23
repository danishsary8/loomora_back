<?php

namespace App\Support;

use App\Models\FocusSession;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A validated Focus session request, normalised to UTC milliseconds.
 *
 * Two requests that mean the same session (for example `Z` versus `+00:00`, or upper-case UUIDs)
 * have the same fingerprint, which is how an identical retry is told apart from a changed one.
 */
final class FocusSessionPayload
{
    /**
     * @param  list<array{type: string, started_ms: int, ended_ms: int, active_ms: int}>  $segments
     * @param  list<int>|null  $laps
     */
    private function __construct(
        public readonly string $id,
        public readonly string $mode,
        public readonly string $endReason,
        public readonly int $startedMs,
        public readonly int $endedMs,
        public readonly ?int $plannedFocusMs,
        public readonly ?string $todoId,
        public readonly ?string $scheduleItemId,
        public readonly ?string $label,
        public readonly array $segments,
        public readonly ?array $laps,
    ) {}

    /**
     * @param  array<string, mixed>  $input  input that passed the field rules
     */
    public static function fromInput(array $input): self
    {
        return new self(
            id: strtolower($input['id']),
            mode: $input['mode'],
            endReason: $input['end_reason'],
            startedMs: self::toMs($input['started_at']),
            endedMs: self::toMs($input['ended_at']),
            plannedFocusMs: isset($input['planned_focus_ms']) ? (int) $input['planned_focus_ms'] : null,
            todoId: isset($input['todo_id']) ? strtolower($input['todo_id']) : null,
            scheduleItemId: isset($input['schedule_item_id']) ? strtolower($input['schedule_item_id']) : null,
            label: $input['label'] ?? null,
            segments: array_map(fn (array $segment) => [
                'type' => $segment['type'],
                'started_ms' => self::toMs($segment['started_at']),
                'ended_ms' => self::toMs($segment['ended_at']),
                'active_ms' => (int) $segment['active_ms'],
            ], array_values($input['segments'])),
            laps: empty($input['laps']) ? null : array_map(intval(...), array_values($input['laps'])),
        );
    }

    /** Active time in Focus segments only; this is the tracked Focus time. */
    public function focusMs(): int
    {
        return $this->activeMs(fn (string $type) => $type === FocusSession::SEGMENT_FOCUS);
    }

    /** Active time in short and long breaks. */
    public function breakMs(): int
    {
        return $this->activeMs(fn (string $type) => $type !== FocusSession::SEGMENT_FOCUS);
    }

    /** Wall time that was neither active Focus nor active break. */
    public function pausedMs(): int
    {
        return $this->endedMs - $this->startedMs - $this->focusMs() - $this->breakMs();
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->mode, $this->endReason, $this->startedMs, $this->endedMs, $this->plannedFocusMs,
            $this->todoId, $this->scheduleItemId, $this->label, $this->segments, $this->laps,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Column values for a new row owned by `$user`.
     *
     * @return array<string, mixed>
     */
    public function attributes(User $user): array
    {
        $startedAt = self::fromMs($this->startedMs);

        return [
            'id' => $this->id,
            'user_id' => $user->id,
            'mode' => $this->mode,
            'end_reason' => $this->endReason,
            'started_at' => $startedAt,
            'ended_at' => self::fromMs($this->endedMs),
            'local_date' => LocalDate::forUser($user, $startedAt),
            'planned_focus_ms' => $this->plannedFocusMs,
            'focus_ms' => $this->focusMs(),
            'break_ms' => $this->breakMs(),
            'paused_ms' => $this->pausedMs(),
            'todo_id' => $this->todoId,
            'schedule_item_id' => $this->scheduleItemId,
            'label' => $this->label,
            'segments' => array_map(fn (array $segment) => [
                'type' => $segment['type'],
                'started_at' => self::isoMs($segment['started_ms']),
                'ended_at' => self::isoMs($segment['ended_ms']),
                'active_ms' => $segment['active_ms'],
            ], $this->segments),
            'laps' => $this->laps,
            'payload_hash' => $this->fingerprint(),
        ];
    }

    /** An ISO 8601 UTC timestamp with milliseconds, as the API returns it. */
    public static function isoMs(int $ms): string
    {
        return self::fromMs($ms)->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Milliseconds since the epoch; finer digits are dropped. */
    private static function toMs(string $timestamp): int
    {
        return (int) CarbonImmutable::parse($timestamp)->format('Uv');
    }

    private static function fromMs(int $ms): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampMsUTC($ms);
    }

    /**
     * @param  callable(string): bool  $matches
     */
    private function activeMs(callable $matches): int
    {
        return array_sum(array_map(
            fn (array $segment) => $matches($segment['type']) ? $segment['active_ms'] : 0,
            $this->segments,
        ));
    }
}
