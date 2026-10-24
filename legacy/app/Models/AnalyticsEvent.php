<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AnalyticsEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['event_name', 'path', 'properties', 'dedupe_key', 'occurred_at'];

    protected function casts(): array
    {
        return ['properties' => 'array', 'occurred_at' => 'datetime'];
    }
}
