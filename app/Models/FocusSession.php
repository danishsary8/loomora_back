<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One finished Focus session, stored once and never changed (B-043).
 *
 * @property string $id
 * @property string $user_id
 * @property string $mode
 * @property string $end_reason
 * @property Carbon $started_at
 * @property Carbon $ended_at
 * @property string $local_date
 * @property int|null $planned_focus_ms
 * @property int $focus_ms
 * @property int $break_ms
 * @property int $paused_ms
 * @property string|null $todo_id
 * @property string|null $schedule_item_id
 * @property string|null $label
 * @property list<array{type: string, started_at: string, ended_at: string, active_ms: int}> $segments
 * @property list<int>|null $laps
 * @property string $payload_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FocusSession extends Model
{
    public const MODE_COUNTDOWN = 'countdown';

    public const MODE_STOPWATCH = 'stopwatch';

    public const MODES = [self::MODE_COUNTDOWN, self::MODE_STOPWATCH];

    public const END_REASONS = ['completed', 'ended_early'];

    public const SEGMENT_FOCUS = 'focus';

    public const SEGMENT_TYPES = [self::SEGMENT_FOCUS, 'short_break', 'long_break'];

    public const MAX_LABEL_LENGTH = 120;

    public const MAX_SEGMENTS = 200;

    public const MAX_LAPS = 1000;

    /** How far ahead of the server clock `ended_at` may be, for client clock skew. */
    public const MAX_CLOCK_SKEW_MS = 300_000;

    public $incrementing = false;

    protected $keyType = 'string';

    /** Millisecond precision for `started_at` and `ended_at`. */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'planned_focus_ms' => 'integer',
            'focus_ms' => 'integer',
            'break_ms' => 'integer',
            'paused_ms' => 'integer',
            'segments' => 'array',
            'laps' => 'array',
        ];
    }
}
