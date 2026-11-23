<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use RuntimeException;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $password
 * @property string|null $google_id
 * @property string|null $google_exchange_digest
 * @property Carbon|null $google_exchange_expires_at
 * @property Carbon|null $email_verified_at
 * @property string $timezone
 * @property string $time_format
 * @property int $week_starts_on
 * @property string|null $goal
 * @property string|null $heard_from
 * @property bool $survey_done
 * @property int $current_streak
 * @property int $longest_streak
 * @property Carbon|null $last_streak_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    public const TIME_FORMATS = ['12h', '24h'];

    public const DEFAULT_TIME_FORMAT = '24h';

    /** 1 = Monday ... 7 = Sunday. */
    public const DEFAULT_WEEK_STARTS_ON = 1;

    /**
     * Mirrors the column defaults so a freshly created user serialises correctly.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'survey_done' => false,
        'time_format' => self::DEFAULT_TIME_FORMAT,
        'week_starts_on' => self::DEFAULT_WEEK_STARTS_ON,
        'current_streak' => 0,
        'longest_streak' => 0,
    ];

    /**
     * Only fields a user may set themselves. Streaks, google_id and
     * verification are written by the server.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'timezone',
        'time_format',
        'week_starts_on',
        'goal',
        'heard_from',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'google_id',
        'google_exchange_digest',
        'google_exchange_expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'google_exchange_expires_at' => 'datetime',
            'password' => 'hashed',
            'survey_done' => 'boolean',
            'week_starts_on' => 'integer',
            'current_streak' => 'integer',
            'longest_streak' => 'integer',
            'last_streak_date' => 'date',
        ];
    }

    /**
     * @return HasMany<Feedback, $this>
     */
    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    public function deletePasswordResetToken(): void
    {
        $broker = config('auth.defaults.passwords');
        $this->getConnection()->table(config("auth.passwords.{$broker}.table"))
            ->where('email', $this->email)->delete();
    }

    /**
     * Delete the account and everything that belongs to it (docs/08 rule 10).
     * Rows with a user_id foreign key cascade in the database; tokens and
     * password resets have no foreign key, so they are removed here.
     */
    public function deleteWithData(): void
    {
        $this->getConnection()->transaction(function () {
            $this->tokens()->delete();

            $this->deletePasswordResetToken();
            $this->getConnection()->table('sessions')->where('user_id', $this->id)->delete();
            if (! $this->delete()) {
                throw new RuntimeException('Account deletion was cancelled.');
            }
        });
    }

    /**
     * @return HasMany<ScheduleItem, $this>
     */
    public function scheduleItems(): HasMany
    {
        return $this->hasMany(ScheduleItem::class);
    }

    /**
     * @return HasMany<FocusSession, $this>
     */
    public function focusSessions(): HasMany
    {
        return $this->hasMany(FocusSession::class);
    }

    /**
     * @return HasMany<DailyIntention, $this>
     */
    public function dailyIntentions(): HasMany
    {
        return $this->hasMany(DailyIntention::class);
    }

    /**
     * @return HasMany<Todo, $this>
     */
    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class);
    }
}
