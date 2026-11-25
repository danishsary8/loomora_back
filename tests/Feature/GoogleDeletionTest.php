<?php

use App\Models\Feedback;
use App\Models\GoogleDeletionChallenge;
use App\Models\ScheduleItem;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\Support\GoogleDeletionProvider;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.google.client_id' => 'synthetic-client',
        'services.google.client_secret' => 'synthetic-secret',
    ]);
    $this->account = User::factory()->create(['password' => null, 'google_id' => 'linked-subject']);
    $this->bearer = $this->account->createToken('initiating')->plainTextToken;
    $this->origin = config('app.frontend_url');
    $this->browserVerifier = str_repeat('a', 42).'A';
    $this->browserChallenge = rtrim(strtr(base64_encode(hash('sha256', $this->browserVerifier, true)), '+/', '-_'), '=');
    $this->challengePath = '/api/v1/me/deletion/google/challenge';
    $this->verifyPath = '/api/v1/me/deletion/google/verify';
});

it('fails closed by default for all three Google operations', function () {
    $headers = ['Authorization' => 'Bearer '.$this->bearer, 'Origin' => $this->origin];
    $this->postJson($this->challengePath, ['browser_challenge' => $this->browserChallenge], $headers)->assertServiceUnavailable();
    $this->postJson($this->verifyPath, [], $headers)->assertServiceUnavailable();
    $this->deleteJson('/api/v1/me', ['google_confirmation' => []], $headers)->assertServiceUnavailable();
    $this->assertModelExists($this->account);
    Http::assertNothingSent();
});

it('starts a purpose-bound authorization code flow for a real bearer', function () {
    config(['services.google.deletion_enabled' => true]);

    $response = $this->postJson($this->challengePath, ['browser_challenge' => $this->browserChallenge], [
        'Authorization' => 'Bearer '.$this->bearer,
        'Origin' => $this->origin,
    ])->assertCreated()->assertJsonStructure(['data' => ['id', 'authorization_url', 'expires_at']]);

    $url = parse_url($response->json('data.authorization_url'));
    parse_str($url['query'], $params);

    expect($url['scheme'])->toBe('https')
        ->and($url['host'])->toBe('accounts.google.com')
        ->and($params)->toMatchArray([
            'client_id' => 'synthetic-client',
            'redirect_uri' => $this->origin.'/auth/google/deletion/callback',
            'response_type' => 'code',
            'scope' => 'openid',
            'prompt' => 'select_account',
            'access_type' => 'online',
            'code_challenge_method' => 'S256',
            'claims' => '{"id_token":{"auth_time":{"essential":true}}}',
        ])
        ->and($params['state'])->not->toBe($params['nonce'])
        ->and($params['code_challenge'])->not->toBe($this->browserChallenge);
    $this->assertModelExists($this->account);
    Http::assertNothingSent();
});

function deletionHeaders(string $bearer, ?string $origin = null): array
{
    Auth::forgetGuards();

    return ['Authorization' => 'Bearer '.$bearer, 'Origin' => $origin ?? config('app.frontend_url')];
}

function startGoogleDeletion($test, ?string $bearer = null): array
{
    config(['services.google.deletion_enabled' => true]);
    $response = $test->postJson($test->challengePath, ['browser_challenge' => $test->browserChallenge],
        deletionHeaders($bearer ?? $test->bearer))->assertCreated()->assertHeader('Cache-Control', 'no-store, private');

    parse_str(parse_url($response->json('data.authorization_url'), PHP_URL_QUERY), $params);

    return [
        'request' => [
            'challenge_id' => $response->json('data.id'),
            'code' => 'synthetic-code',
            'state' => $params['state'],
            'browser_verifier' => $test->browserVerifier,
        ],
        'nonce' => $params['nonce'],
        'code_challenge' => $params['code_challenge'],
        'expires_at' => $response->json('data.expires_at'),
    ];
}

function verifyGoogleDeletion($test, array $input, ?string $bearer = null): TestResponse
{
    return $test->postJson($test->verifyPath, $input, deletionHeaders($bearer ?? $test->bearer));
}

function finalizeGoogleDeletion($test, array $input, ?string $bearer = null, array $extra = []): TestResponse
{
    return $test->deleteJson('/api/v1/me', [
        'google_confirmation' => array_intersect_key($input, array_flip(['challenge_id', 'browser_verifier'])),
        ...$extra,
    ], deletionHeaders($bearer ?? $test->bearer));
}

function fakeGoogleDeletion(array $challenge, array $claims = [], string $algorithm = 'RS256', string $keyId = 'test-key', bool $tamper = false): void
{
    [$token, $jwks] = GoogleDeletionProvider::assertion($challenge['nonce'], $claims, $algorithm, $keyId);
    if ($tamper) {
        $segments = explode('.', $token);
        $segments[2][0] = $segments[2][0] === 'a' ? 'b' : 'a';
        $token = implode('.', $segments);
    }

    Http::fake([
        GoogleDeletionProvider::TOKEN_URL => Http::response(['id_token' => $token, 'access_token' => 'discard-this']),
        GoogleDeletionProvider::JWKS_URL => Http::response($jwks),
    ]);
}

it('exchanges with PKCE, confirms once and deletes only the bound account', function () {
    $this->freezeTime();
    $challenge = startGoogleDeletion($this);
    $request = $challenge['request'];
    $record = GoogleDeletionChallenge::findOrFail($request['challenge_id']);
    expect($record->getRawOriginal('pkce_verifier'))->not->toBe($record->pkce_verifier)
        ->and($record->state_digest)->toBe(hash('sha256', $request['state']))
        ->and($record->nonce_digest)->toBe(hash('sha256', $challenge['nonce']));

    fakeGoogleDeletion($challenge, ['azp' => null]);
    verifyGoogleDeletion($this, $request)->assertOk()->assertExactJson(['data' => [
        'id' => $record->id,
        'expires_at' => now()->addSeconds(120)->toIso8601ZuluString(),
    ]]);
    Http::assertSent(fn ($outbound) => $outbound->url() === GoogleDeletionProvider::TOKEN_URL
        && $outbound['redirect_uri'] === $this->origin.'/auth/google/deletion/callback'
        && $outbound['grant_type'] === 'authorization_code'
        && $outbound['code'] === $request['code']
        && GoogleDeletionProvider::base64Url(hash('sha256', $outbound['code_verifier'], true)) === $challenge['code_challenge']);
    $record->refresh();
    expect($record->status)->toBe('confirmed')
        ->and($record->state_digest)->toBeNull()
        ->and($record->nonce_digest)->toBeNull()
        ->and($record->pkce_verifier)->toBeNull();

    verifyGoogleDeletion($this, $request)->assertUnprocessable()->assertJsonValidationErrors('google_confirmation');
    Http::assertSentCount(2);
    expect($this->account->tokens()->count())->toBe(1);

    $anotherToken = $this->account->createToken('other-session');
    $other = User::factory()->create(['password' => null, 'google_id' => 'other-subject']);
    $otherToken = $other->createToken('other-owner');
    $todo = Todo::factory()->for($this->account)->create();
    $subtask = Todo::factory()->for($this->account)->create(['parent_id' => $todo->id]);
    $subtask->delete();
    $schedule = ScheduleItem::factory()->for($this->account)->create();
    $exception = $schedule->exceptions()->create(['date' => '2026-11-20', 'type' => 'skip']);
    $checkin = $schedule->checkins()->create(['date' => '2026-11-19', 'done_at' => now()]);
    $intention = $this->account->dailyIntentions()->create(['local_date' => '2026-11-20', 'text' => 'My intention']);
    $feedback = new Feedback(['message' => 'Deletion fixture']);
    $feedback->user()->associate($this->account)->save();
    $otherTodo = Todo::factory()->for($other)->create();
    Password::createToken($this->account);
    DB::table('sessions')->insert([
        'id' => 'synthetic-session', 'user_id' => $this->account->id, 'payload' => '', 'last_activity' => 0,
    ]);

    finalizeGoogleDeletion($this, $request, $anotherToken->plainTextToken)->assertJsonValidationErrors('google_confirmation');
    finalizeGoogleDeletion($this, $request, $otherToken->plainTextToken)->assertJsonValidationErrors('google_confirmation');
    finalizeGoogleDeletion($this, $request, extra: ['user_id' => $other->id])->assertNoContent();
    finalizeGoogleDeletion($this, $request)->assertUnauthorized();
    $this->assertModelMissing($this->account);
    $this->assertModelMissing($record);
    $this->assertModelMissing($anotherToken->accessToken);
    $this->assertModelMissing($todo);
    $this->assertModelMissing($subtask);
    $this->assertModelMissing($schedule);
    $this->assertModelMissing($exception);
    $this->assertModelMissing($checkin);
    $this->assertModelMissing($intention);
    $this->assertModelMissing($feedback);
    $this->assertDatabaseMissing('sessions', ['id' => 'synthetic-session']);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $this->account->email]);
    $this->assertModelExists($other);
    $this->assertModelExists($otherToken->accessToken);
    $this->assertModelExists($otherTodo);
});

it('rejects invalid Google assertions with a single proof error', function (string $case) {
    $this->freezeSecond();
    $challenge = startGoogleDeletion($this);
    $now = now()->timestamp;
    $overrides = match ($case) {
        'missing auth_time' => ['auth_time' => null],
        'string auth_time' => ['auth_time' => (string) $now],
        'stale auth_time' => ['auth_time' => $now - 300],
        'future auth_time' => ['auth_time' => $now + 31],
        'auth_time after iat' => ['auth_time' => $now, 'iat' => $now - 31],
        'issuer' => ['iss' => 'https://untrusted.example.test'],
        'audience' => ['aud' => 'another-client'],
        'audience list' => ['aud' => ['synthetic-client', 'another-client']],
        'azp' => ['azp' => 'another-client'],
        'nonce' => ['nonce' => 'different-nonce'],
        'same email different subject' => ['sub' => 'another-subject', 'email' => $this->account->email],
        'subject case' => ['sub' => 'Linked-subject'],
        'expired JWT' => ['exp' => $now - 1],
        'future iat' => ['iat' => $now + 31],
        'missing iat' => ['iat' => null],
        'nbf' => ['nbf' => $now + 31],
        default => [],
    };
    fakeGoogleDeletion(
        $challenge,
        $overrides,
        $case === 'algorithm' ? 'HS256' : 'RS256',
        $case === 'unknown key' ? 'wrong-key' : 'test-key',
        $case === 'bad signature',
    );

    verifyGoogleDeletion($this, $challenge['request'])->assertUnprocessable()
        ->assertJsonValidationErrors('google_confirmation')->assertDontSee('synthetic-code');
    $this->assertDatabaseHas('google_deletion_challenges', [
        'id' => $challenge['request']['challenge_id'], 'status' => 'failed', 'pkce_verifier' => null,
        'state_digest' => null, 'nonce_digest' => null,
    ]);
    verifyGoogleDeletion($this, $challenge['request'])->assertUnprocessable();
    Http::assertSentCount(2);
    $this->assertModelExists($this->account);
})->with([
    'missing auth_time', 'string auth_time', 'stale auth_time', 'future auth_time', 'auth_time after iat',
    'issuer', 'audience', 'audience list', 'azp', 'nonce', 'same email different subject', 'subject case',
    'expired JWT', 'future iat', 'missing iat', 'nbf', 'algorithm', 'unknown key', 'bad signature',
]);

it('caps confirmation expiry and rejects a final request at expiry', function (string $limit) {
    $this->freezeTime();
    $challenge = startGoogleDeletion($this);
    if ($limit === 'challenge') {
        $this->travel(295)->seconds();
    }
    $now = now()->timestamp;
    $overrides = match ($limit) {
        'Google session age' => ['auth_time' => $now - 295],
        'ID token expiry' => ['exp' => $now + 5],
        'allowed clock skew' => ['iat' => $now + 30, 'auth_time' => $now + 30, 'exp' => $now + 60],
        default => [],
    };
    fakeGoogleDeletion($challenge, $overrides);
    $remaining = $limit === 'allowed clock skew' ? 60 : 5;
    verifyGoogleDeletion($this, $challenge['request'])->assertOk()
        ->assertJsonPath('data.expires_at', now()->addSeconds($remaining)->toIso8601ZuluString());
    $this->travel($remaining)->seconds();
    finalizeGoogleDeletion($this, $challenge['request'])->assertUnprocessable()->assertJsonValidationErrors('google_confirmation');
    $this->assertModelExists($this->account);
})->with(['challenge', 'Google session age', 'ID token expiry', 'allowed clock skew']);

it('rejects old challenges, the wrong tab, state, account or bearer before exchange', function () {
    $this->freezeTime();
    $old = startGoogleDeletion($this);
    $pending = startGoogleDeletion($this);
    $request = $pending['request'];
    $this->assertDatabaseMissing('google_deletion_challenges', ['id' => $old['request']['challenge_id']]);
    verifyGoogleDeletion($this, $old['request'])->assertUnprocessable();
    verifyGoogleDeletion($this, [...$request, 'state' => $old['request']['state']])->assertUnprocessable();
    verifyGoogleDeletion($this, [...$request, 'browser_verifier' => str_repeat('b', 42).'A'])->assertUnprocessable();
    verifyGoogleDeletion($this, $request, $this->account->createToken('other')->plainTextToken)->assertUnprocessable();
    verifyGoogleDeletion($this, $request, User::factory()->create(['password' => null, 'google_id' => 'different'])->createToken('other')->plainTextToken)
        ->assertUnprocessable();
    $this->travel(5)->minutes();
    verifyGoogleDeletion($this, $request)->assertUnprocessable();
    Http::assertNothingSent();
});

it('requires a real current bearer, exact origin and JSON body', function () {
    config(['services.google.deletion_enabled' => true]);
    $input = ['browser_challenge' => $this->browserChallenge];
    $this->postJson($this->challengePath, $input, ['Origin' => $this->origin])->assertUnauthorized();
    $this->actingAs($this->account, 'web')->postJson($this->challengePath, $input, ['Origin' => $this->origin])->assertUnauthorized();
    Auth::forgetGuards();

    $bearer = $this->bearer;
    $this->postJson($this->challengePath, $input, ['Authorization' => 'Bearer '.$bearer])->assertForbidden();
    $this->postJson($this->challengePath, $input, deletionHeaders($bearer, $this->origin.'.other'))->assertForbidden();
    $this->post($this->challengePath, $input, deletionHeaders($bearer))->assertUnsupportedMediaType();
    $this->postJson($this->challengePath, $input, [...deletionHeaders($bearer), 'Content-Type' => 'application/jsonp'])
        ->assertUnsupportedMediaType();
    $this->postJson($this->challengePath.'?'.http_build_query($input), [], deletionHeaders($bearer))->assertJsonValidationErrors('browser_challenge');

    $expired = $this->account->createToken('expired', expiresAt: now()->subSecond());
    $this->postJson($this->challengePath, $input, deletionHeaders($expired->plainTextToken))->assertUnauthorized();
    $expired->accessToken->delete();
    $this->postJson($this->challengePath, $input, deletionHeaders($expired->plainTextToken))->assertUnauthorized();
    $this->assertDatabaseCount('google_deletion_challenges', 0);
    Http::assertNothingSent();
});

it('keeps password deletion independent and rejects mixed-method and bare requests', function () {
    $challenge = startGoogleDeletion($this);
    $this->deleteJson('/api/v1/me', [], deletionHeaders($this->bearer))->assertJsonValidationErrors('password');
    $this->deleteJson('/api/v1/me', ['password' => 'x'], deletionHeaders($this->bearer))->assertJsonValidationErrors('password');
    finalizeGoogleDeletion($this, $challenge['request'], extra: ['password' => null])->assertJsonValidationErrors('password');
    finalizeGoogleDeletion($this, $challenge['request'])->assertJsonValidationErrors('google_confirmation');
    fakeGoogleDeletion($challenge);
    verifyGoogleDeletion($this, $challenge['request'])->assertOk();

    $this->account->forceFill(['password' => 'correct-password'])->save();
    finalizeGoogleDeletion($this, $challenge['request'])->assertJsonValidationErrors('google_confirmation');
    $this->postJson($this->challengePath, ['browser_challenge' => $this->browserChallenge], deletionHeaders($this->bearer))
        ->assertJsonValidationErrors('google_confirmation');
    $this->deleteJson('/api/v1/me', ['password' => 'incorrect'], deletionHeaders($this->bearer))->assertJsonValidationErrors('password');
    $this->deleteJson('/api/v1/me', ['password' => 'correct-password'], deletionHeaders($this->bearer))->assertNoContent();
});

it('invalidates a reserved attempt on provider rejection or outage without disclosing details', function (string $failure, int $status) {
    $challenge = startGoogleDeletion($this);
    [$token] = GoogleDeletionProvider::assertion($challenge['nonce']);
    $script = match ($failure) {
        'cancelled or invalid grant' => [GoogleDeletionProvider::TOKEN_URL => Http::response(['error' => 'invalid_grant', 'error_description' => 'private-marker'], 400)],
        'connection failure' => [GoogleDeletionProvider::TOKEN_URL => Http::failedConnection('private-marker')],
        'provider failure' => [GoogleDeletionProvider::TOKEN_URL => Http::response(['error' => 'private-marker'], 502)],
        'signing keys unavailable' => [
            GoogleDeletionProvider::TOKEN_URL => Http::response(['id_token' => $token]),
            GoogleDeletionProvider::JWKS_URL => Http::response(['error' => 'private-marker'], 503),
        ],
    };
    Http::fake($script);

    verifyGoogleDeletion($this, $challenge['request'])->assertStatus($status)->assertDontSee('private-marker');
    finalizeGoogleDeletion($this, $challenge['request'])->assertJsonValidationErrors('google_confirmation');
    $this->assertDatabaseHas('google_deletion_challenges', ['id' => $challenge['request']['challenge_id'], 'status' => 'failed']);
    $this->assertModelExists($this->account);
})->with([
    ['cancelled or invalid grant', 422], ['connection failure', 503], ['provider failure', 503], ['signing keys unavailable', 503],
]);

it('rolls back proof consumption and cleanup if account deletion fails and permits retry', function () {
    config(['app.debug' => true]);
    $challenge = startGoogleDeletion($this);
    fakeGoogleDeletion($challenge);
    verifyGoogleDeletion($this, $challenge['request'])->assertOk();
    $todo = Todo::factory()->for($this->account)->create();
    $otherToken = $this->account->createToken('other-session');

    $cancel = true;
    User::deleting(function () use (&$cancel) {
        if ($cancel) {
            throw new RuntimeException('private failure marker');
        }
    });
    finalizeGoogleDeletion($this, $challenge['request'])->assertInternalServerError()
        ->assertExactJson(['message' => 'Account deletion could not be completed.']);
    $this->assertModelExists($this->account);
    $this->assertModelExists($todo);
    $this->assertModelExists($otherToken->accessToken);
    $this->assertDatabaseHas('google_deletion_challenges', ['id' => $challenge['request']['challenge_id'], 'status' => 'confirmed']);

    $cancel = false;
    finalizeGoogleDeletion($this, $challenge['request'])->assertNoContent();
});

it('independently throttles all three operations and emits Retry-After', function () {
    $challenge = startGoogleDeletion($this);
    startGoogleDeletion($this);
    startGoogleDeletion($this);
    $reply = $this->postJson($this->challengePath, ['browser_challenge' => $this->browserChallenge], deletionHeaders($this->bearer));
    $reply->assertTooManyRequests()->assertHeader('Retry-After');

    foreach (range(1, 5) as $attempt) {
        verifyGoogleDeletion($this, $challenge['request'])->assertUnprocessable();
        finalizeGoogleDeletion($this, $challenge['request'])->assertUnprocessable();
    }
    verifyGoogleDeletion($this, $challenge['request'])->assertTooManyRequests()->assertHeader('Retry-After');
    finalizeGoogleDeletion($this, $challenge['request'])->assertTooManyRequests()->assertHeader('Retry-After');
    Http::assertNothingSent();
});

it('prunes expired challenges and retains live ones', function () {
    $this->freezeTime();
    $old = startGoogleDeletion($this);
    $this->travel(5)->minutes();
    $other = User::factory()->create(['password' => null, 'google_id' => 'different'])->createToken('other');
    $live = startGoogleDeletion($this, $other->plainTextToken);
    $this->artisan('model:prune', ['--model' => [GoogleDeletionChallenge::class]])->assertSuccessful();

    $this->assertDatabaseMissing('google_deletion_challenges', ['id' => $old['request']['challenge_id']]);
    $this->assertDatabaseHas('google_deletion_challenges', ['id' => $live['request']['challenge_id']]);
});

it('throttles starts by IP across unrelated accounts', function () {
    foreach (range(1, 10) as $attempt) {
        $user = User::factory()->create(['password' => null, 'google_id' => 'linked-'.$attempt]);
        startGoogleDeletion($this, $user->createToken('test')->plainTextToken);
    }

    $this->postJson($this->challengePath, ['browser_challenge' => $this->browserChallenge], deletionHeaders($this->bearer))
        ->assertTooManyRequests()->assertHeader('Retry-After');
    $this->assertDatabaseCount('google_deletion_challenges', 10);
});

it('rejects login exchange credentials and requires the tab secret for final submit', function () {
    $challenge = startGoogleDeletion($this);
    $request = $challenge['request'];
    $loginCode = 'login-only-exchange-credential';
    $this->account->forceFill([
        'google_exchange_digest' => hash('sha256', $loginCode),
        'google_exchange_expires_at' => now()->addMinutes(5),
    ])->save();
    Http::fake([GoogleDeletionProvider::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);
    verifyGoogleDeletion($this, [...$request, 'code' => $loginCode])->assertJsonValidationErrors('google_confirmation');
    finalizeGoogleDeletion($this, ['challenge_id' => $request['challenge_id']])->assertJsonValidationErrors('google_confirmation.browser_verifier');
    finalizeGoogleDeletion($this, [...$request, 'browser_verifier' => str_repeat('b', 42).'A'])
        ->assertJsonValidationErrors('google_confirmation');
    $this->assertModelExists($this->account);
});
