<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

final class User extends Authenticatable implements MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens;

    use HasFactory;
    use MustVerifyEmail;
    use Notifiable;
    use SoftDeletes;

    /**
     * Mass-assignable attributes. Deliberately excludes id/timestamps and any
     * privilege-bearing column.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'terms_accepted_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'google_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return HasOne<NotificationSetting, $this> */
    public function notificationSetting(): HasOne
    {
        return $this->hasOne(NotificationSetting::class);
    }

    /** @return HasMany<ChecklistLog, $this> */
    public function checklistLogs(): HasMany
    {
        return $this->hasMany(ChecklistLog::class);
    }

    /** @return HasMany<DayGroup, $this> */
    public function dayGroups(): HasMany
    {
        return $this->hasMany(DayGroup::class);
    }

    /** @return HasMany<PrayerTimeCache, $this> */
    public function prayerTimeCaches(): HasMany
    {
        return $this->hasMany(PrayerTimeCache::class);
    }
}
