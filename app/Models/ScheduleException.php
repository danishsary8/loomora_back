<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $schedule_item_id
 * @property string $date
 * @property string $type
 * @property string|null $title
 * @property string|null $start_time
 * @property string|null $end_time
 */
class ScheduleException extends Model
{
    use HasUuids;

    public const SKIP = 'skip';

    public const OVERRIDE = 'override';

    /**
     * @var list<string>
     */
    protected $fillable = ['date', 'type', 'title', 'start_time', 'end_time'];

    /**
     * @return BelongsTo<ScheduleItem, $this>
     */
    public function scheduleItem(): BelongsTo
    {
        return $this->belongsTo(ScheduleItem::class);
    }
}
