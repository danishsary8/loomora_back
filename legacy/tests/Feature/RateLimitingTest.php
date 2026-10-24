<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_limit_returns_standard_envelope_and_retry_after(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/v1/auth/register', []);
        }

        $this->postJson('/api/v1/auth/register', [])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('error.code', 'too_many_requests')
            ->assertJsonPath('error.message', 'Request limit reached. Please wait before trying again.');
    }

    public function test_forgot_password_limit_returns_standard_envelope(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/password/forgot', ['email' => 'missing@example.test']);
        }

        $this->postJson('/api/v1/password/forgot', ['email' => 'missing@example.test'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('error.code', 'too_many_requests');
    }

    public function test_resend_verification_limit_returns_standard_envelope(): void
    {
        Sanctum::actingAs(User::factory()->unverified()->create());
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/api/v1/email/verification-notification');
        }

        $this->postJson('/api/v1/email/verification-notification')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('error.code', 'too_many_requests');
    }

    public function test_authenticated_api_has_generous_per_user_limit(): void
    {
        Sanctum::actingAs(User::factory()->create());
        for ($attempt = 0; $attempt < 120; $attempt++) {
            $this->getJson('/api/v1/auth/me')->assertOk();
        }

        $response = $this->getJson('/api/v1/auth/me');
        $response
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('error.details.retry_after', fn ($seconds) => is_int($seconds) && $seconds > 0 && $seconds <= 60);
    }
}
