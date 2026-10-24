<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NotificationSetting extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'enabled', 'minutes_before'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'minutes_before' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
