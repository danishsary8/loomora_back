<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

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
}
