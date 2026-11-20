<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\WithActiveAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Http\Resources\UserResource;
use App\Mail\VerifyEmailMail;
use App\Models\User;
use App\Services\EmailVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class EmailVerificationController extends Controller
{
    use WithActiveAccount;

    public function __construct(private EmailVerification $verification) {}

    /** Public: the link may be opened on a device where the user is signed out. */
    public function verify(VerifyEmailRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::lockForUpdate()->find($request->validated('id'));

            if (! $user || ! $this->verification->isValid($user, (int) $request->validated('expires'), $request->validated('signature'))) {
                throw ValidationException::withMessages(['signature' => [__('auth_flow.verification_invalid')]]);
            }

            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            return $user;
        });

        return response()->json([
            'message' => __('auth_flow.verified'),
            'user' => new UserResource($user),
        ]);
    }

    public function resend(Request $request): JsonResponse
    {
        $mail = $this->withActiveAccount($request->user(), fn (User $user) => $user->email_verified_at === null
            ? (new VerifyEmailMail($user, $this->verification->urlFor($user)))->to($user)
            : null);

        if ($mail !== null) {
            try {
                Mail::send($mail);
            } catch (Throwable) {
                throw new RuntimeException('Verification email delivery failed.');
            }
        }

        return response()->json(['message' => __('auth_flow.verification_sent')]);
    }
}
