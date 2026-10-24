<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AccountEssentialsTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_sends_verification_email(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/register', ['name' => 'New User', 'email' => 'new@example.com', 'password' => 'StrongPass9', 'password_confirmation' => 'StrongPass9', 'terms_accepted' => true])->assertCreated()->assertJsonPath('data.is_email_verified', false)->assertJsonPath('data.terms_accepted_at', fn ($value) => $value !== null);
        $user = User::whereEmail('new@example.com')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_registration_requires_terms_acceptance(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'No Consent', 'email' => 'no-consent@example.com', 'password' => 'StrongPass9', 'password_confirmation' => 'StrongPass9'])->assertStatus(422)->assertJsonPath('error.details.terms_accepted.0', fn ($value) => is_string($value));
        $this->assertDatabaseMissing('users', ['email' => 'no-consent@example.com']);
    }

    public function test_signed_verification_link_verifies_and_tampering_fails(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('api.v1.verification.verify', now()->addMinutes(10), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->getJson($url)->assertOk();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $other = User::factory()->unverified()->create();
        $tampered = preg_replace('#/email/verify/'.$user->id.'/#', '/email/verify/'.$other->id.'/', $url);
        $this->getJson($tampered)->assertForbidden();
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
        $expired = URL::temporarySignedRoute('api.v1.verification.verify', now()->subMinute(), ['id' => $other->id, 'hash' => sha1($other->email)]);
        $this->getJson($expired)->assertForbidden();
    }

    public function test_unverified_user_gets_actionable_mutation_response(): void
    {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/day-groups', ['name' => 'Weekdays', 'weekdays' => [1]])->assertForbidden()->assertJsonPath('error.code', 'email_not_verified')->assertJsonPath('error.details.action', 'resend_verification');
    }

    public function test_verification_resend_sends_only_for_unverified_users(): void
    {
        Notification::fake();
        $unverified = User::factory()->unverified()->create();
        Sanctum::actingAs($unverified);
        $this->postJson('/api/v1/email/verification-notification')->assertOk()->assertJsonPath('data.message', 'Verification email sent. Please check your inbox.');
        Notification::assertSentTo($unverified, VerifyEmail::class);

        Notification::fake();
        $verified = User::factory()->create();
        Sanctum::actingAs($verified);
        $this->postJson('/api/v1/email/verification-notification')->assertOk()->assertJsonPath('data.message', 'Your email is already verified.');
        Notification::assertNothingSent();
    }

    public function test_forgot_password_response_is_identical_for_existing_and_missing_email(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'known@example.com']);
        $known = $this->postJson('/api/v1/password/forgot', ['email' => 'known@example.com'])->assertOk()->json();
        $missing = $this->postJson('/api/v1/password/forgot', ['email' => 'missing@example.com'])->assertOk()->json();
        $this->assertSame($known, $missing);
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_changes_password_and_invalidates_all_tokens(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.com', 'password' => 'OldStrong9']);
        $user->createToken('phone');
        $user->createToken('web');
        $token = Password::broker()->createToken($user);
        $this->postJson('/api/v1/password/reset', ['email' => $user->email, 'token' => $token, 'password' => 'NewStrong8', 'password_confirmation' => 'NewStrong8'])->assertOk();
        $this->assertTrue(Hash::check('NewStrong8', $user->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'NewStrong8'])->assertOk();
    }

    public function test_expired_reset_token_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'expired@example.com']);
        $token = Password::broker()->createToken($user);
        DB::table('password_reset_tokens')->where('email', $user->email)->update(['created_at' => now()->subMinutes(61)]);
        $this->postJson('/api/v1/password/reset', ['email' => $user->email, 'token' => $token, 'password' => 'NewStrong8', 'password_confirmation' => 'NewStrong8'])->assertStatus(422)->assertJsonPath('error.code', 'invalid_reset_token');
    }

    public function test_reset_rejects_a_token_issued_for_another_account(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $token = Password::broker()->createToken($owner);
        $this->postJson('/api/v1/password/reset', ['email' => $other->email, 'token' => $token, 'password' => 'NewStrong8', 'password_confirmation' => 'NewStrong8'])->assertStatus(422)->assertJsonPath('error.code', 'invalid_reset_token');
    }
}
