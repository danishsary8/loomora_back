<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EmailVerificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class EmailVerificationController extends Controller
{
    public function __construct(private readonly EmailVerificationService $verification) {}

    public function verify(Request $request, int $id, string $hash): JsonResponse|RedirectResponse
    {
        $this->verification->verify($id, $hash);
        if ($request->expectsJson()) {
            return ApiResponse::success(['message' => 'Email verified successfully.']);
        }

        return redirect()->away(rtrim((string) config('app.frontend_url'), '/').'/email-verified?status=verified');
    }

    public function resend(Request $request): JsonResponse
    { /** @var User $user */ $user = $request->user();
        $sent = $this->verification->resend($user);

        return ApiResponse::success(['message' => $sent ? 'Verification email sent. Please check your inbox.' : 'Your email is already verified.']);
    }
}
