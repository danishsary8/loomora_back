<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * TEMPORARY — DELETE AFTER PRODUCTION EMAIL DELIVERY IS VERIFIED.
 */
final class MailDiagnosticController extends Controller
{
    public function configuration(Request $request): JsonResponse
    {
        $this->authorizeDiagnosticRequest($request, 'configuration');

        return ApiResponse::success([
            'mailer' => config('mail.default'),
            'resend_api_key_set' => filled(config('services.resend.key')),
            'from_address' => config('mail.from.address'),
            'environment' => config('app.env'),
        ]);
    }

    public function sendTest(Request $request): JsonResponse
    {
        $this->authorizeDiagnosticRequest($request, 'test-send');

        $email = (string) $request->query('email', '');
        $validator = Validator::make(['email' => $email], ['email' => ['required', 'email:rfc']]);
        if ($validator->fails()) {
            return ApiResponse::error('validation_failed', 'A valid test email address is required.', 422, $validator->errors()->toArray());
        }

        if (config('mail.default') !== 'resend') {
            return ApiResponse::error('mail_not_configured', 'MAIL_MAILER is not set to resend.', 503);
        }

        if (blank(config('services.resend.key'))) {
            return ApiResponse::error('mail_not_configured', 'RESEND_API_KEY is not set.', 503);
        }

        $from = (string) config('mail.from.address');
        if (Validator::make(['from' => $from], ['from' => ['required', 'email:rfc']])->fails()) {
            return ApiResponse::error('mail_not_configured', 'MAIL_FROM_ADDRESS is missing or invalid.', 503);
        }

        try {
            Mail::raw('Daycraft email delivery is configured correctly.', function ($message) use ($email): void {
                $message->to($email)->subject('Daycraft email delivery test');
            });
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('mail_delivery_failed', 'The mail provider rejected the test message. Check Render and Resend logs.', 502);
        }

        return ApiResponse::success(['message' => 'Test email accepted by Resend. Check the inbox and Resend delivery log.']);
    }

    private function authorizeDiagnosticRequest(Request $request, string $action): void
    {
        $configuredToken = (string) config('app.diagnostic_token', '');
        $providedToken = (string) $request->query('token', '');
        $authorized = $configuredToken !== ''
            && $providedToken !== ''
            && hash_equals($configuredToken, $providedToken);

        // Never log the token, recipient address, or other query parameters.
        Log::warning('TEMPORARY mail diagnostic endpoint accessed.', [
            'action' => $action,
            'authorized' => $authorized,
        ]);

        abort_unless($authorized, 404);
    }
}
