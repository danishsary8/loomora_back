<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string|null $schedule_item_id
 * @property string|null $local_date
 * @property string|null $title
 * @property string|null $body
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Note extends Model
{
    use HasUuids, SoftDeletes;

    public const MAX_IMPORT = 500;

    public const RESTORE_WITHIN_DAYS = 7;

    public const MAX_TITLE_LENGTH = 120;

    public const MAX_BODY_LENGTH = 10000;

    protected $fillable = ['title', 'body', 'schedule_item_id', 'local_date'];
}
