<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.frontend_url' => 'https://app.loomora.test',
        'services.google' => ['client_id' => 'test-client', 'client_secret' => 'test-secret', 'redirect' => 'https://api.loomora.test/api/v1/auth/google/callback'],
    ]);
});

/** Make Socialite return this Google profile on the callback. */
function fakeGoogle(array $attrs = []): void
{
    $attrs = array_merge(['id' => 'g-123', 'email' => 'sokha@gmail.com', 'name' => 'Sokha', 'email_verified' => true], $attrs);

    $user = (new GoogleUser)->setRaw(['email_verified' => $attrs['email_verified']])->map([
        'id' => $attrs['id'], 'email' => $attrs['email'], 'name' => $attrs['name'],
    ]);

    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($user);
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

/** A valid state as the redirect step would create it. */
function googleState(string $intent, ?string $timezone = null, int $ttl = 600): string
{
    return Crypt::encryptString(json_encode(['intent' => $intent, 'timezone' => $timezone, 'expires' => now()->addSeconds($ttl)->getTimestamp(), 'nonce' => 'n']));
}

/** Follow the callback and return the frontend query params. */
function callbackParams($test, string $state): array
{
    $location = $test->get('/api/v1/auth/google/callback?code=google-code&state='.urlencode($state))
        ->assertRedirect()
        ->headers->get('Location');

    expect($location)->toStartWith('https://app.loomora.test/auth/google/callback?');
    parse_str(parse_url($location, PHP_URL_QUERY), $params);

    return $params;
}

describe('redirect', function () {
    it('sends the user to Google with an encrypted intent', function () {
        $location = $this->get('/api/v1/auth/google/redirect?intent=sign_up&timezone=Asia/Phnom_Penh')
            ->assertRedirect()
            ->headers->get('Location');

        expect($location)->toStartWith('https://accounts.google.com/o/oauth2/auth?');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $state = json_decode(Crypt::decryptString($query['state']), true);

        expect($query['client_id'])->toBe('test-client')
            ->and($state['intent'])->toBe('sign_up')
            ->and($state['timezone'])->toBe('Asia/Phnom_Penh');
    });

    it('requires a valid intent', function () {
        $this->getJson('/api/v1/auth/google/redirect')->assertUnprocessable()->assertJsonValidationErrors('intent');
        $this->getJson('/api/v1/auth/google/redirect?intent=hack')->assertUnprocessable();
    });
});

describe('callback', function () {
    it('signs up a new user and returns a one-time code', function () {
        fakeGoogle();

        $params = callbackParams($this, googleState('sign_up', 'Asia/Phnom_Penh'));

        expect($params)->toHaveKey('code')->not->toHaveKey('error');
        $user = User::sole();
        expect($user->email)->toBe('sokha@gmail.com')
            ->and($user->google_id)->toBe('g-123')
            ->and($user->password)->toBeNull()
            ->and($user->timezone)->toBe('Asia/Phnom_Penh')
            ->and($user->email_verified_at)->not->toBeNull();
    });

    it('rejects sign-up when the account already exists', function () {
        User::factory()->create(['email' => 'sokha@gmail.com']);
        fakeGoogle();

        expect(callbackParams($this, googleState('sign_up')))->toBe(['error' => 'account_already_exists'])
            ->and(User::count())->toBe(1);
    });

    it('rejects sign-in when no account exists', function () {
        fakeGoogle();

        expect(callbackParams($this, googleState('sign_in')))->toBe(['error' => 'account_not_found'])
            ->and(User::count())->toBe(0);
    });

    it('signs in an existing email user and links their Google id', function () {
        $user = User::factory()->unverified()->create(['email' => 'sokha@gmail.com']);
        fakeGoogle(['email' => 'Sokha@Gmail.com']);

        expect(callbackParams($this, googleState('sign_in')))->toHaveKey('code');
        $user->refresh();
        expect($user->google_id)->toBe('g-123')->and($user->email_verified_at)->not->toBeNull();
    });

    it('finds a linked user by Google id even if their email changed', function () {
        User::factory()->create(['email' => 'old@example.com', 'google_id' => 'g-123']);
        fakeGoogle(['email' => 'new@gmail.com']);

        expect(callbackParams($this, googleState('sign_in')))->toHaveKey('code')
            ->and(User::count())->toBe(1);
    });

    it('refuses an unverified Google email', function () {
        User::factory()->create(['email' => 'sokha@gmail.com']);
        fakeGoogle(['email_verified' => false]);

        expect(callbackParams($this, googleState('sign_in')))->toBe(['error' => 'email_not_verified']);
    });

    it('fails safely on a bad, expired or missing state, or a Google error', function () {
        fakeGoogle();

        expect(callbackParams($this, 'not-encrypted'))->toBe(['error' => 'google_failed'])
            ->and(callbackParams($this, googleState('sign_up', null, -1)))->toBe(['error' => 'google_failed'])
            ->and(callbackParams($this, Crypt::encryptString(json_encode(['intent' => 'admin', 'expires' => now()->addHour()->getTimestamp()]))))->toBe(['error' => 'google_failed']);

        $location = $this->get('/api/v1/auth/google/callback?error=access_denied&state='.urlencode(googleState('sign_in')))->headers->get('Location');
        expect($location)->toEndWith('error=google_failed')
            ->and(User::count())->toBe(0);
    });

    it('fails safely when Google rejects the code', function () {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andThrow(new RuntimeException('invalid_grant'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        expect(callbackParams($this, googleState('sign_in')))->toBe(['error' => 'google_failed']);
    });
});

describe('exchange', function () {
    it('trades the code for a token exactly once', function () {
        fakeGoogle();
        $code = callbackParams($this, googleState('sign_up'))['code'];

        $response = $this->postJson('/api/v1/auth/google/exchange', ['code' => $code])
            ->assertOk()
            ->assertJsonStructure(['token', 'expires_at', 'user' => ['id', 'email']])
            ->assertJsonPath('user.email', 'sokha@gmail.com');

        $this->withToken($response->json('token'))->getJson('/api/v1/me')->assertOk();

        $this->postJson('/api/v1/auth/google/exchange', ['code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
        expect(PersonalAccessToken::count())->toBe(1);
    });

    it('rejects an expired or unknown code', function () {
        fakeGoogle();
        $code = callbackParams($this, googleState('sign_up'))['code'];

        $this->travel(301)->seconds();

        $this->postJson('/api/v1/auth/google/exchange', ['code' => $code])->assertUnprocessable();
        $this->postJson('/api/v1/auth/google/exchange', ['code' => str_repeat('x', 64)])->assertUnprocessable();
        $this->postJson('/api/v1/auth/google/exchange', ['code' => 'short'])->assertUnprocessable();
    });

    it('lets a Google-only user sign in again but not with a password', function () {
        fakeGoogle();
        $code = callbackParams($this, googleState('sign_up'))['code'];
        $this->postJson('/api/v1/auth/google/exchange', ['code' => $code])->assertOk();

        $this->postJson('/api/v1/auth/login', ['email' => 'sokha@gmail.com', 'password' => 'anything'])->assertUnprocessable();
    });
});
