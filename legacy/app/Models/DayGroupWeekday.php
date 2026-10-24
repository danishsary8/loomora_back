<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DayGroupWeekday extends Model
{
    protected $table = 'day_group_weekdays';

    public $timestamps = false;

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }

    public function dayGroup(): BelongsTo
    {
        return $this->belongsTo(DayGroup::class);
    }
}
