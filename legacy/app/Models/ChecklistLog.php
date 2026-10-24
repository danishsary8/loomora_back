<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ChecklistLog extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'checklist_item_id', 'log_date', 'is_checked'];

    protected function casts(): array
    {
        return [
            'log_date' => 'date:Y-m-d',
            'is_checked' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ChecklistItem, $this> */
    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class);
    }
}
