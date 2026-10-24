<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AccountService
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    public function permanentlyDelete(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $userId = (int) $user->id;
            $user->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->forceDelete();
            $this->analytics->accountDeleted($userId);
        });
    }
}
