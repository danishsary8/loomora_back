<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $user_id
 * @property string $message
 * @property Carbon|null $created_at
 */
class Feedback extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'feedback';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'message',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
