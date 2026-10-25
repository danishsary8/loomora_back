<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $schedule_item_id
 * @property string $date
 * @property Carbon $done_at
 */
class ScheduleCheckin extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = ['date', 'done_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['done_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<ScheduleItem, $this>
     */
    public function scheduleItem(): BelongsTo
    {
        return $this->belongsTo(ScheduleItem::class);
    }
}
