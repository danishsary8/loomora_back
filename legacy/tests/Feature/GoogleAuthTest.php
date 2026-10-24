<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

final class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private function mockGoogle(string $id, string $email, string $name = 'Google User'): void
    {
        $google = new GoogleUser;
        $google->id = $id;
        $google->email = $email;
        $google->name = $name;
        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->once()->andReturnSelf();
        $provider->shouldReceive('user')->once()->andReturn($google);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);
    }

    private function callGoogleCallback(string $state = 'safe-state')
    {
        Cache::put('oauth:google:state:'.hash('sha256', $state), true, 600);

        return $this->getJson('/api/v1/auth/google/callback?state='.$state.'&code=google-code');
    }

    public function test_google_callback_creates_verified_passwordless_user(): void
    {
        $this->mockGoogle('google-1', 'newgoogle@example.com', 'New Google');
        $response = $this->callGoogleCallback()->assertOk()->assertJsonStructure(['data' => ['code']]);
        $user = User::whereEmail('newgoogle@example.com')->firstOrFail();
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertNull($user->password);
        $this->assertSame('google-1', $user->google_id);
        $this->assertSame('google', AnalyticsEvent::where('event_name', 'signup_completed')->firstOrFail()->properties['method']);
        $code = $response->json('data.code');
        $this->postJson('/api/v1/auth/google/exchange', ['code' => $code])->assertOk()->assertJsonStructure(['data' => ['id', 'email'], 'meta' => ['token']]);
        $this->postJson('/api/v1/auth/google/exchange', ['code' => $code])->assertStatus(422)->assertJsonPath('error.code', 'oauth_code_invalid');
    }

    public function test_google_callback_links_existing_email_without_duplicate(): void
    {
        $existing = User::factory()->unverified()->create(['email' => 'same@example.com', 'google_id' => null]);
        $this->mockGoogle('google-2', 'same@example.com', $existing->name);
        $this->callGoogleCallback()->assertOk();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('google-2', $existing->fresh()->google_id);
        $this->assertTrue($existing->fresh()->hasVerifiedEmail());
    }

    public function test_google_callback_rejects_missing_or_replayed_state(): void
    {
        $this->getJson('/api/v1/auth/google/callback?state=bad&code=x')->assertStatus(422)->assertJsonPath('error.code', 'oauth_state_invalid');
    }

    public function test_google_callback_rejects_provider_identity_without_email(): void
    {
        $this->mockGoogle('google-no-email', '', 'No Email');
        $this->callGoogleCallback()->assertStatus(422)->assertJsonPath('error.code', 'oauth_email_missing');
        $this->assertDatabaseCount('users', 0);
    }
}
