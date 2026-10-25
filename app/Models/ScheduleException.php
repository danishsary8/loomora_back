<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
