<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\DeleteAccountRequest;
use App\Models\User;
use App\Services\AccountService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class AccountController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    public function destroy(DeleteAccountRequest $request): JsonResponse
    {
        /** @var User $user */ $user = $request->user();
        $this->accounts->permanentlyDelete($user);

        return ApiResponse::success(['message' => 'Your account and associated data were permanently deleted.']);
    }
}
