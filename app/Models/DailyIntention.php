<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line a user sets for a local day.
 *
 * @property string $id
 * @property string $user_id
 * @property string $local_date
 * @property string $text
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DailyIntention extends Model
{
    use HasUuids;

    public const MAX_TEXT_LENGTH = 200;

    /** How far from the user's today a day may be, in either direction. */
    public const MAX_DAYS_FROM_TODAY = 366;

    /**
     * @var list<string>
     */
    protected $fillable = ['local_date', 'text'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
