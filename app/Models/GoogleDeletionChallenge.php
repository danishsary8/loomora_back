<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property int $personal_access_token_id
 * @property string $google_subject
 * @property string $purpose
 * @property string $browser_challenge
 * @property string|null $state_digest
 * @property string|null $nonce_digest
 * @property string|null $pkce_verifier
 * @property string $status
 * @property Carbon $expires_at
 * @property Carbon|null $confirmation_expires_at
 */
class GoogleDeletionChallenge extends Model
{
    use HasUuids, MassPrunable;

    public const PURPOSE = 'account_deletion';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $hidden = ['browser_challenge', 'state_digest', 'nonce_digest', 'pkce_verifier'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'personal_access_token_id' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'confirmation_expires_at' => 'datetime',
            'pkce_verifier' => 'encrypted',
        ];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return $this->newQuery()->where('expires_at', '<=', now());
    }
}
