<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ChecklistItem extends Model
{
    use HasFactory;

    protected $fillable = ['day_group_id', 'label', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function dayGroup(): BelongsTo
    {
        return $this->belongsTo(DayGroup::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ChecklistLog::class);
    }
}
