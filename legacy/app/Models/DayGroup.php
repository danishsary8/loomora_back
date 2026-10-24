<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

final class DayGroup extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'color', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function timelineEntries(): HasMany
    {
        return $this->hasMany(TimelineEntry::class);
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(ChecklistItem::class);
    }

    public function weekdays(): HasMany
    {
        return $this->hasMany(DayGroupWeekday::class);
    }

    public function weekdayValues(): array
    {
        $weekdays = $this->relationLoaded('weekdays')
            ? $this->weekdays->pluck('weekday')
            : DB::table('day_group_weekdays')->where('day_group_id', $this->id)->orderBy('weekday')->pluck('weekday');

        return $weekdays->map(fn ($value) => (int) $value)->sort()->values()->all();
    }

    public function syncWeekdays(array $weekdays): void
    {
        DB::transaction(function () use ($weekdays): void {
            $this->weekdays()->delete();
            $this->weekdays()->createMany(array_map(fn ($day) => ['weekday' => $day], array_values(array_unique($weekdays))));
        });
        $this->unsetRelation('weekdays');
    }
}
