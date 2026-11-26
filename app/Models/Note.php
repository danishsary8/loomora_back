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
 * @property int $revision
 * @property bool $pinned
 * @property string|null $color
 * @property list<string>|null $tags
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

    /** B-056: a note color is one of the six category pastels. */
    public const COLORS = ['study', 'work', 'health', 'life', 'rest', 'other'];

    public const MAX_TAGS = 5;

    public const MAX_TAG_LENGTH = 24;

    /** How a skipped import row compares with the account note (B-052). */
    public const IMPORT_IDENTICAL = 'identical';

    public const IMPORT_DIFFERENT = 'different';

    public const IMPORT_DELETED = 'deleted';

    /** The server sets `revision` (B-053); it is never fillable. */
    protected $fillable = ['title', 'body', 'schedule_item_id', 'local_date', 'pinned', 'color', 'tags'];

    protected $attributes = ['revision' => 1, 'pinned' => false];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['revision' => 'integer', 'pinned' => 'boolean', 'tags' => 'array'];
    }

    /**
     * Tags are trimmed, lose a leading "#", and are stored lowercase without duplicates, so "Work"
     * and "#work" are one tag. An empty list is stored as null.
     *
     * @param  list<string>|null  $tags
     * @return list<string>|null
     */
    public static function normalizeTags(?array $tags): ?array
    {
        $clean = array_values(array_unique(array_filter(array_map(
            fn (string $tag) => mb_strtolower(ltrim(trim($tag), '#')),
            $tags ?? [],
        ), fn (string $tag) => $tag !== '')));

        return $clean === [] ? null : $clean;
    }
}
