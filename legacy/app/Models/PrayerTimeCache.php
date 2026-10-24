<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PrayerTimeCache extends Model
{
    use HasFactory;

    protected $table = 'prayer_time_cache';

    protected $fillable = [
        'user_id',
        'cache_date',
        'latitude',
        'longitude',
        'fajr',
        'dhuhr',
        'asr',
        'maghrib',
        'isha',
    ];

    protected function casts(): array
    {
        return [
            'cache_date' => 'date:Y-m-d',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string> Prayer name => "HH:MM"
     */
    public function timings(): array
    {
        return [
            'Fajr' => substr((string) $this->fajr, 0, 5),
            'Dhuhr' => substr((string) $this->dhuhr, 0, 5),
            'Asr' => substr((string) $this->asr, 0, 5),
            'Maghrib' => substr((string) $this->maghrib, 0, 5),
            'Isha' => substr((string) $this->isha, 0, 5),
        ];
    }
}
