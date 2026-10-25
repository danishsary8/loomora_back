<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\TodoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $title
 * @property string $category
 * @property Carbon|null $due_date
 * @property Carbon|null $done_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Todo extends Model
{
    /** @use HasFactory<TodoFactory> */
    use HasFactory, HasUuids;

    /** The fixed set of 6 categories (docs/08 rule 12). */
    public const CATEGORIES = ['study', 'work', 'health', 'life', 'rest', 'other'];

    /** R1 limit per user (docs/08 rule 11). */
    public const MAX_PER_USER = 500;

    /** Overdue items older than this many days are marked "old" (docs/08 rule 2). */
    public const OLD_AFTER_DAYS = 30;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'category',
        'due_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date:Y-m-d',
            'done_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The Today list for a local date (docs/08 rule 1): due that day,
     * undated and not done, or overdue. Future-dated items are hidden.
     *
     * @param  Builder<Todo>  $query
     */
    public function scopeForToday(Builder $query, CarbonInterface $today): void
    {
        $date = $today->toDateString();

        $query->where(function (Builder $q) use ($date) {
            $q->whereDate('due_date', $date)
                ->orWhere(fn (Builder $q) => $q->whereNull('due_date')->whereNull('done_at'))
                ->orWhere(fn (Builder $q) => $q->whereDate('due_date', '<', $date)->whereNull('done_at'));
        });
    }

    /** Overdue is computed, never stored (docs/08 rule 2). */
    public function isOverdueOn(CarbonInterface $today): bool
    {
        return $this->done_at === null
            && $this->due_date !== null
            && $this->due_date->lt($today->copy()->startOfDay());
    }

    public function isOldOn(CarbonInterface $today): bool
    {
        return $this->isOverdueOn($today)
            && $this->due_date->lt($today->copy()->startOfDay()->subDays(self::OLD_AFTER_DAYS));
    }
}
