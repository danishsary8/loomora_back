<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Category;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TimelineEntry extends Model
{
    use HasFactory;

    protected $fillable = ['day_group_id', 'start_time', 'end_time', 'category', 'description', 'sort_order'];

    protected function casts(): array
    {
        return ['category' => Category::class, 'sort_order' => 'integer'];
    }

    public function dayGroup(): BelongsTo
    {
        return $this->belongsTo(DayGroup::class);
    }

    public function startHm(): string
    {
        return substr((string) $this->start_time, 0, 5);
    }

    public function endHm(): string
    {
        return substr((string) $this->end_time, 0, 5);
    }
}
