<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GoogleExchangeRequest;
use App\Http\Resources\UserResource;
use App\Services\GoogleAuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class GoogleAuthController extends Controller
{
    public function __construct(private readonly GoogleAuthService $google) {}

    public function redirect(): JsonResponse
    {
        return ApiResponse::success(['url' => $this->google->authorizationUrl()]);
    }

    public function callback(Request $request): JsonResponse|RedirectResponse
    {
        try {
            $code = $this->google->callback((string) $request->query('state', ''));
        } catch (RuntimeException $exception) {
            [$error,$message] = array_pad(explode('|', $exception->getMessage(), 2), 2, 'Google sign-in could not be completed.');

            return $this->failure($request, $error, $message);
        }
        if ($request->expectsJson()) {
            return ApiResponse::success(['code' => $code]);
        }

        return redirect()->away(rtrim((string) config('app.frontend_url'), '/').'/auth/google/callback?code='.urlencode($code));
    }

    public function exchange(GoogleExchangeRequest $request): JsonResponse
    {
        $result = $this->google->exchange($request->string('code')->value());
        if ($result === null) {
            return ApiResponse::error('oauth_code_invalid', 'This Google sign-in code is invalid or expired.', 422);
        }

        return ApiResponse::success(new UserResource($result['user']), ['token' => $result['token'], 'token_type' => 'Bearer']);
    }

    private function failure(Request $request, string $code, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return ApiResponse::error($code, $message, 422);
        }

        return redirect()->away(rtrim((string) config('app.frontend_url'), '/').'/login?oauth_error='.urlencode($message));
    }
}
