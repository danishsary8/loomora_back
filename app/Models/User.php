<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $password
 * @property string|null $google_id
 * @property Carbon|null $email_verified_at
 * @property string $timezone
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

    /**
     * Mirrors the column defaults so a freshly created user serialises correctly.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'survey_done' => false,
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
        'goal',
        'heard_from',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'google_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'survey_done' => 'boolean',
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

    /**
     * Delete the account and everything that belongs to it (docs/08 rule 10).
     * Rows with a user_id foreign key cascade in the database; tokens and
     * password resets have no foreign key, so they are removed here.
     */
    public function deleteWithData(): void
    {
        DB::transaction(function () {
            $this->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $this->email)->delete();
            DB::table('sessions')->where('user_id', $this->id)->delete();
            $this->delete();
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
     * @return HasMany<Todo, $this>
     */
    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class);
    }
}
