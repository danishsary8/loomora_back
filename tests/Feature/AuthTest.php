<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

function registerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Sokha',
        'email' => 'sokha@example.com',
        'password' => 'correct-horse-battery',
        'timezone' => 'Asia/Phnom_Penh',
    ], $overrides);
}

describe('register', function () {
    it('creates a user with a uuid and returns a token', function () {
        $response = $this->postJson('/api/v1/auth/register', registerPayload());

        $response->assertCreated()
            ->assertJsonStructure(['token', 'expires_at', 'user' => ['id', 'name', 'email', 'timezone', 'created_at']])
            ->assertJsonPath('user.email', 'sokha@example.com')
            ->assertJsonMissingPath('user.password');

        expect(Str::isUuid($response->json('user.id')))->toBeTrue();
        $this->assertDatabaseHas('users', ['email' => 'sokha@example.com', 'timezone' => 'Asia/Phnom_Penh']);
    });

    it('rejects a duplicate email', function () {
        User::factory()->create(['email' => 'sokha@example.com']);

        $this->postJson('/api/v1/auth/register', registerPayload(['email' => 'Sokha@Example.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    });

    it('validates required fields, password length and time zone', function () {
        $this->postJson('/api/v1/auth/register', registerPayload(['password' => 'short', 'timezone' => 'Mars/Base']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password', 'timezone']);

        $this->postJson('/api/v1/auth/register', [])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['name', 'email', 'password', 'timezone']]);
    });

    it('ignores server-owned fields sent by the client', function () {
        $this->postJson('/api/v1/auth/register', registerPayload([
            'id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'current_streak' => 99,
            'email_verified_at' => now()->toIso8601String(),
        ]))->assertCreated()
            ->assertJsonPath('user.current_streak', 0)
            ->assertJsonPath('user.email_verified_at', null);
    });
});

describe('login', function () {
    it('returns a token for the right password', function () {
        User::factory()->create(['email' => 'sokha@example.com', 'password' => 'correct-horse-battery']);

        $this->postJson('/api/v1/auth/login', ['email' => 'sokha@example.com', 'password' => 'correct-horse-battery'])
            ->assertOk()
            ->assertJsonStructure(['token', 'expires_at', 'user' => ['id']]);
    });

    it('rejects a wrong password without issuing a token', function () {
        User::factory()->create(['email' => 'sokha@example.com', 'password' => 'correct-horse-battery']);

        $this->postJson('/api/v1/auth/login', ['email' => 'sokha@example.com', 'password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email')
            ->assertJsonMissingPath('token');

        expect(PersonalAccessToken::count())->toBe(0);
    });

    it('rejects an unknown email the same way', function () {
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'whatever'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    });

    it('rejects password login for a Google-only account', function () {
        User::factory()->create(['email' => 'g@example.com', 'password' => null, 'google_id' => 'g-123']);

        $this->postJson('/api/v1/auth/login', ['email' => 'g@example.com', 'password' => ''])
            ->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['email' => 'g@example.com', 'password' => 'anything'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    });

    it('issues tokens that expire after 30 days', function () {
        User::factory()->create(['email' => 'sokha@example.com', 'password' => 'correct-horse-battery']);
        $this->freezeTime();

        $this->postJson('/api/v1/auth/login', ['email' => 'sokha@example.com', 'password' => 'correct-horse-battery'])->assertOk();

        expect(PersonalAccessToken::first()->expires_at->toDateTimeString())->toBe(now()->addDays(30)->toDateTimeString());
    });

    it('limits auth attempts to 10 per minute per IP', function () {
        foreach (range(1, 10) as $i) {
            $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'x'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'x'])->assertTooManyRequests();
    });
});

describe('me and logout', function () {
    it('returns the current user for a valid token', function () {
        $token = $this->postJson('/api/v1/auth/register', registerPayload())->json('token');

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'sokha@example.com')
            ->assertJsonMissingPath('data.password');
    });

    it('returns a JSON 401 without a token, even without an Accept header', function () {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonStructure(['message']);
        $this->get('/api/v1/me')->assertUnauthorized()->assertJsonStructure(['message']);
    });

    it('revokes only the current token on logout', function () {
        User::factory()->create(['email' => 'sokha@example.com', 'password' => 'correct-horse-battery']);
        $login = fn () => $this->postJson('/api/v1/auth/login', ['email' => 'sokha@example.com', 'password' => 'correct-horse-battery'])->json('token');
        $phone = $login();
        $laptop = $login();

        $this->withToken($phone)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->withToken($phone)->getJson('/api/v1/me')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withToken($laptop)->getJson('/api/v1/me')->assertOk();
    });

    it('rejects an expired token', function () {
        $token = $this->postJson('/api/v1/auth/register', registerPayload())->json('token');

        $this->travel(31)->days();

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    });
});
