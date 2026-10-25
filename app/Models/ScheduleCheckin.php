<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
