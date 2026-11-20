<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;

trait WithActiveAccount
{
    /**
     * Recheck the authenticated account after taking the same lock as reset and deletion.
     *
     * @template TResult
     *
     * @param  Closure(User): TResult  $operation
     * @return TResult
     */
    protected function withActiveAccount(User $authenticated, Closure $operation): mixed
    {
        return $authenticated->getConnection()->transaction(function () use ($authenticated, $operation) {
            $user = User::lockForUpdate()->find($authenticated->id);
            if (! $user) {
                throw new AuthenticationException;
            }

            $bearerId = data_get($authenticated->currentAccessToken(), 'id');
            if ($bearerId !== null) {
                $current = $user->tokens()->find($bearerId);
                $expiration = config('sanctum.expiration');
                if (! $current || $current->expires_at?->isPast()
                    || ($expiration && ! $current->created_at->gt(now()->subMinutes($expiration)))) {
                    throw new AuthenticationException;
                }

                $user->withAccessToken($current);
            }

            return $operation($user);
        });
    }
}
