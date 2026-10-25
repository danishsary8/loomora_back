<?php

use App\Mail\ResetPasswordMail;
use App\Mail\VerifyEmailMail;
use App\Models\User;
use App\Services\EmailVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    config(['app.frontend_url' => 'https://app.loomora.test']);
});

/** Pull the query parameters out of the link in a sent mail. */
function linkParams(string $url): array
{
    parse_str(parse_url($url, PHP_URL_QUERY), $params);

    return $params;
}

describe('email verification', function () {
    it('sends a verification email on register', function () {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Sokha', 'email' => 'sokha@example.com', 'password' => 'correct-horse-battery', 'timezone' => 'Asia/Phnom_Penh',
        ])->assertCreated();

        Mail::assertSent(VerifyEmailMail::class, fn (VerifyEmailMail $mail) => $mail->hasTo('sokha@example.com')
            && str_starts_with($mail->url, 'https://app.loomora.test/verify-email?'));
    });

    it('still registers when sending the email fails', function () {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('Brevo down'));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Sokha', 'email' => 'sokha@example.com', 'password' => 'correct-horse-battery', 'timezone' => 'Asia/Phnom_Penh',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'sokha@example.com']);
    });

    it('verifies the email with the link from the mail', function () {
        $user = User::factory()->unverified()->create();
        $params = linkParams(app(EmailVerification::class)->urlFor($user));

        $this->postJson('/api/v1/auth/email/verify', $params)
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        expect($user->fresh()->email_verified_at)->not->toBeNull();
    });

    it('rejects a tampered, expired or stale link', function () {
        $user = User::factory()->unverified()->create();
        $verification = app(EmailVerification::class);

        $tampered = linkParams($verification->urlFor($user));
        $tampered['signature'] = str_repeat('0', 64);
        $this->postJson('/api/v1/auth/email/verify', $tampered)->assertUnprocessable()->assertJsonValidationErrors('signature');

        $expired = linkParams($verification->urlFor($user));
        $this->travel(config('app.verify_email_expire') + 1)->minutes();
        $this->postJson('/api/v1/auth/email/verify', $expired)->assertUnprocessable();
        $this->travelBack();

        $stale = linkParams($verification->urlFor($user));
        $user->forceFill(['email' => 'new@example.com'])->save();
        $this->postJson('/api/v1/auth/email/verify', $stale)->assertUnprocessable();

        $this->postJson('/api/v1/auth/email/verify', [])->assertUnprocessable()->assertJsonValidationErrors(['id', 'expires', 'signature']);

        expect($user->fresh()->email_verified_at)->toBeNull();
    });

    it('resends the email to an unverified, signed-in user', function () {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/email/resend')->assertOk();

        Mail::assertSent(VerifyEmailMail::class, fn ($mail) => $mail->hasTo($user->email));
    });

    it('does not resend to an already verified user', function () {
        $this->actingAs(User::factory()->create(), 'sanctum')->postJson('/api/v1/auth/email/resend')->assertOk();

        Mail::assertNothingSent();
    });

    it('throttles resend to 3 per 10 minutes', function () {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user, 'sanctum');

        foreach (range(1, 3) as $i) {
            $this->postJson('/api/v1/auth/email/resend')->assertOk();
        }

        $this->postJson('/api/v1/auth/email/resend')->assertTooManyRequests();
        Mail::assertSent(VerifyEmailMail::class, 3);
    });

    it('requires sign-in to resend', function () {
        $this->postJson('/api/v1/auth/email/resend')->assertUnauthorized();
    });
});

describe('password reset', function () {
    it('emails a reset link that points to the frontend', function () {
        $user = User::factory()->create(['email' => 'sokha@example.com']);

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'Sokha@Example.com'])->assertOk();

        Mail::assertSent(ResetPasswordMail::class, fn (ResetPasswordMail $mail) => $mail->hasTo($user->email)
            && str_starts_with($mail->url, 'https://app.loomora.test/reset-password?'));
    });

    it('gives the same answer for an unknown email and sends nothing', function () {
        $known = User::factory()->create(['email' => 'sokha@example.com']);

        $a = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.com'])->assertOk()->json();
        Mail::assertNothingSent();
        $b = $this->postJson('/api/v1/auth/password/forgot', ['email' => $known->email])->assertOk()->json();

        expect($a)->toBe($b);
    });

    it('resets the password with the emailed token and signs out other devices', function () {
        $user = User::factory()->create(['email' => 'sokha@example.com', 'password' => 'old-password-123']);
        $user->createToken('phone');

        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email]);
        $url = Mail::sent(ResetPasswordMail::class)->first()->url;
        $params = linkParams($url);

        $this->postJson('/api/v1/auth/password/reset', [...$params, 'password' => 'new-password-456'])->assertOk();

        expect(Hash::check('new-password-456', $user->fresh()->password))->toBeTrue()
            ->and(PersonalAccessToken::count())->toBe(0);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'new-password-456'])->assertOk();
    });

    it('rejects an invalid, reused or expired token', function () {
        $user = User::factory()->create(['email' => 'sokha@example.com', 'password' => 'old-password-123']);

        $this->postJson('/api/v1/auth/password/reset', ['token' => 'nope', 'email' => $user->email, 'password' => 'new-password-456'])
            ->assertUnprocessable()->assertJsonValidationErrors('token');

        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email]);
        $params = linkParams(Mail::sent(ResetPasswordMail::class)->first()->url);

        $this->postJson('/api/v1/auth/password/reset', [...$params, 'password' => 'new-password-456'])->assertOk();
        $this->postJson('/api/v1/auth/password/reset', [...$params, 'password' => 'another-pass-789'])->assertUnprocessable();

        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email]);
        $fresh = linkParams(Mail::sent(ResetPasswordMail::class)->last()->url);
        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();
        $this->postJson('/api/v1/auth/password/reset', [...$fresh, 'password' => 'another-pass-789'])->assertUnprocessable();

        expect(Hash::check('new-password-456', $user->fresh()->password))->toBeTrue();
    });

    it('validates the new password', function () {
        $this->postJson('/api/v1/auth/password/reset', ['token' => 'x', 'email' => 'a@example.com', 'password' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    });
});

describe('mail content', function () {
    it('renders both emails with the link and translated text', function () {
        $user = User::factory()->make(['name' => 'Sokha']);

        $verify = (new VerifyEmailMail($user, 'https://app.loomora.test/verify-email?x=1'))->render();
        $reset = (new ResetPasswordMail($user, 'https://app.loomora.test/reset-password?y=2'))->render();

        expect($verify)->toContain('Hi Sokha,', __('mail.verify.action'), 'https://app.loomora.test/verify-email?x=1')
            ->and($reset)->toContain(__('mail.reset.action'), 'https://app.loomora.test/reset-password?y=2', '60 minutes');
    });
});
