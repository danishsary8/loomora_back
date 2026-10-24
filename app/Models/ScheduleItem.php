<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleItem extends Model
{
    use HasFactory, HasUuids;

    /** The fixed set of 6 categories (docs/08 rule 12). */
    public const CATEGORIES = ['study', 'work', 'health', 'life', 'rest', 'other'];

    /** R1 limit per user (docs/08 rule 11). */
    public const MAX_PER_USER = 100;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'category',
        'start_time',
        'end_time',
        'days',
        'starts_on',
        'ends_on',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days' => 'array',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Stored as HH:MM:SS; the API speaks HH:MM. */
    public static function hhmm(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }
}
