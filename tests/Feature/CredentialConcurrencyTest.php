<?php

use App\Models\GoogleDeletionChallenge;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\GoogleDeletionProvider;

uses(DatabaseMigrations::class);

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires independent PostgreSQL connections and row locks.');
    }
});

function credentialRequestProcess(array $fixture): Process
{
    $database = DB::connection()->getConfig();
    $process = new Process([PHP_BINARY, 'tests/Support/credential-request.php', json_encode($fixture, JSON_THROW_ON_ERROR)], dirname(__DIR__, 2), [
        'APP_ENV' => 'testing',
        'APP_DEBUG' => 'false',
        'APP_KEY' => config('app.key'),
        'BCRYPT_ROUNDS' => '4',
        'CACHE_STORE' => $fixture['cache_store'] ?? 'array',
        'DB_CONNECTION' => 'pgsql',
        'DB_URL' => $database['url'] ?? '',
        'DB_HOST' => $database['host'],
        'DB_PORT' => (string) $database['port'],
        'DB_DATABASE' => $database['database'],
        'DB_USERNAME' => $database['username'],
        'DB_PASSWORD' => $database['password'],
        'MAIL_MAILER' => 'array',
        'SESSION_DRIVER' => 'array',
    ], timeout: 45);
    $process->start();

    return $process;
}

function waitForCredentialRequestLock(Process $process, int $blockerPid): int
{
    $deadline = microtime(true) + 30;
    do {
        $process->checkTimeout();
        $pid = (int) strtok($process->getOutput(), "\n");
        if ($pid && DB::selectOne('select ? = any(pg_blocking_pids(?)) as blocked', [$blockerPid, $pid])->blocked) {
            return $pid;
        }
        if (! $process->isRunning()) {
            $response = json_decode(trim(substr($process->getOutput(), strlen((string) $pid))), true);
            $status = $response['status'] ?? 'unavailable';
            throw new RuntimeException("Credential request finished before the expected PostgreSQL lock (HTTP {$status}).");
        }
        usleep(20_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException("PostgreSQL did not report the expected blocker for credential request {$pid}.");
}

function overlapCredentialRequests(array $winner, array $contender): array
{
    $coordinator = DB::selectOne('select pg_backend_pid() as pid')->pid;
    DB::selectOne('select pg_advisory_lock(36036)');
    try {
        $first = credentialRequestProcess($winner);
        $winnerPid = waitForCredentialRequestLock($first, $coordinator);
        $second = credentialRequestProcess($contender);
        waitForCredentialRequestLock($second, $winnerPid);

        DB::selectOne('select pg_advisory_unlock(36036)');

        return array_map(credentialResponse(...), [$first, $second]);
    } finally {
        ($second ?? null)?->stop(0);
        ($first ?? null)?->stop(0);
        DB::selectOne('select pg_advisory_unlock(36036)');
    }
}

function credentialResponse(Process $request): array
{
    $request->wait();
    expect($request->isSuccessful())->toBeTrue();
    [, $response] = explode("\n", trim($request->getOutput()), 2);

    return json_decode($response, true, flags: JSON_THROW_ON_ERROR);
}

function concurrentResetFixture(User $user): array
{
    return [
        'method' => 'POST',
        'path' => '/api/v1/auth/password/reset',
        'body' => ['email' => $user->email, 'token' => Password::createToken($user), 'password' => 'new-password'],
    ];
}

function concurrentGoogleExchangeFixture(User $user): array
{
    $code = Str::random(64);
    $user->forceFill([
        'google_exchange_digest' => hash('sha256', $code),
        'google_exchange_expires_at' => now()->addMinutes(5),
    ])->save();

    return ['method' => 'POST', 'path' => '/api/v1/auth/google/exchange', 'body' => ['code' => $code]];
}

function concurrentGoogleCallbackFixture(string $email, string $intent, string $subject = 'synthetic-google-subject'): array
{
    return [
        'method' => 'GET',
        'path' => '/api/v1/auth/google/callback',
        'body' => [],
        'intent' => $intent,
        'google' => ['id' => $subject, 'email' => $email, 'name' => 'Synthetic User'],
    ];
}

function concurrentDeleteFixture(User $user): array
{
    return [
        'method' => 'DELETE',
        'path' => '/api/v1/me',
        'bearer' => $user->createToken('delete')->plainTextToken,
        'body' => ['password' => 'original-password'],
    ];
}

function concurrentForgotFixture(User $user): array
{
    return ['method' => 'POST', 'path' => '/api/v1/auth/password/forgot', 'body' => ['email' => $user->email]];
}

it('redeems the same reset credential only once under overlap', function () {
    $user = User::factory()->create();
    $oldBearer = $user->createToken('old');
    $unrelated = User::factory()->create()->createToken('unrelated');
    $first = concurrentResetFixture($user);
    $second = $first;
    $second['body']['password'] = 'different-password';
    $first['pause_before'] = 'delete from "personal_access_tokens"';

    [$accepted, $rejected] = overlapCredentialRequests($first, $second);

    expect($accepted['status'])->toBe(200)
        ->and($rejected['status'])->toBe(422)
        ->and($rejected['body']['errors'])->toHaveKey('token')
        ->and(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    $this->assertModelMissing($oldBearer->accessToken);
    $this->assertModelExists($unrelated->accessToken);
});

it('orders password sign-in and reset without retaining an old-password bearer', function (bool $resetWins) {
    $user = User::factory()->create(['password' => 'original-password']);
    $reset = concurrentResetFixture($user);
    $login = [
        'method' => 'POST',
        'path' => '/api/v1/auth/login',
        'body' => ['email' => $user->email, 'password' => 'original-password'],
    ];

    if ($resetWins) {
        [$resetResponse, $loginResponse] = overlapCredentialRequests(
            [...$reset, 'pause_before' => 'delete from "personal_access_tokens"'],
            $login,
        );
        expect($loginResponse['status'])->toBe(422)->and($loginResponse['body']['errors'])->toHaveKey('email');
    } else {
        [$loginResponse, $resetResponse] = overlapCredentialRequests(
            [...$login, 'pause_before' => 'insert into "personal_access_tokens"'],
            $reset,
        );
        expect($loginResponse['status'])->toBe(200);
        $this->withToken($loginResponse['body']['token'])->getJson('/api/v1/me')->assertUnauthorized();
    }

    expect($resetResponse['status'])->toBe(200)->and($user->tokens()->count())->toBe(0);
    $this->postJson('/api/v1/auth/login', [...$login['body'], 'password' => 'new-password'])
        ->assertOk()->assertJsonPath('user.id', $user->id);
})->with(['reset first' => true, 'login first' => false]);

it('revokes a Google bearer when exchange has reached issuance before reset', function () {
    $user = User::factory()->create(['google_id' => 'synthetic-google-subject', 'password' => null]);
    $exchange = [...concurrentGoogleExchangeFixture($user), 'pause_before' => 'insert into "personal_access_tokens"'];

    [$issued, $reset] = overlapCredentialRequests($exchange, concurrentResetFixture($user));

    expect($issued['status'])->toBe(200)->and($reset['status'])->toBe(200)
        ->and($user->fresh()->google_id)->toBe('synthetic-google-subject')
        ->and($user->tokens()->count())->toBe(0);
    $this->withToken($issued['body']['token'])->getJson('/api/v1/me')->assertUnauthorized();
});

it('prevents credential commits after account deletion', function (string $path) {
    $user = User::factory()->create(['password' => 'original-password', 'google_id' => 'synthetic-google-subject']);
    $todo = Todo::factory()->for($user)->create();
    $unrelated = User::factory()->create()->createToken('unrelated');
    $bearer = $user->createToken('delete')->plainTextToken;
    $reset = concurrentResetFixture($user);
    $contender = match ($path) {
        'reset' => $reset,
        'login' => ['method' => 'POST', 'path' => '/api/v1/auth/login', 'body' => ['email' => $user->email, 'password' => 'original-password']],
        'exchange' => concurrentGoogleExchangeFixture($user),
    };

    [$deleted, $rejected] = overlapCredentialRequests([
        'method' => 'DELETE',
        'path' => '/api/v1/me',
        'body' => ['password' => 'original-password'],
        'bearer' => $bearer,
        'pause_before' => 'delete from "users"',
    ], $contender);

    expect($deleted['status'])->toBe(204)->and($rejected['status'])->toBe(422);
    $this->assertModelMissing($user);
    $this->assertModelMissing($todo);
    $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    $this->assertModelExists($unrelated->accessToken);
})->with(['reset', 'login', 'exchange']);

it('rejects account deletion waiting with a revoked bearer even if the password is unchanged', function () {
    $user = User::factory()->create(['password' => 'original-password']);
    $todo = Todo::factory()->for($user)->create();
    $bearer = $user->createToken('delete')->plainTextToken;
    $reset = concurrentResetFixture($user);
    $reset['body']['password'] = 'original-password';

    [$accepted, $rejected] = overlapCredentialRequests(
        [...$reset, 'pause_before' => 'delete from "personal_access_tokens"'],
        ['method' => 'DELETE', 'path' => '/api/v1/me', 'body' => ['password' => 'original-password'], 'bearer' => $bearer],
    );

    expect($accepted['status'])->toBe(200)->and($rejected['status'])->toBe(401);
    $this->assertModelExists($user);
    $this->assertModelExists($todo);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    $this->withToken($bearer)->getJson('/api/v1/me')->assertUnauthorized();
});

it('rejects a Google grant waiting behind a successful password reset', function () {
    $user = User::factory()->create(['google_id' => 'synthetic-google-subject']);
    [$reset, $exchange] = overlapCredentialRequests(
        [...concurrentResetFixture($user), 'pause_before' => 'delete from "personal_access_tokens"'],
        concurrentGoogleExchangeFixture($user),
    );

    expect($reset['status'])->toBe(200)
        ->and($exchange['status'])->toBe(422)
        ->and($exchange['body']['errors'])->toHaveKey('code')
        ->and($user->tokens()->count())->toBe(0);
});

it('consumes one grant only once when two exchanges overlap', function () {
    $user = User::factory()->create(['google_id' => 'synthetic-google-subject']);
    $exchange = concurrentGoogleExchangeFixture($user);
    [$first, $second] = overlapCredentialRequests(
        [...$exchange, 'pause_before' => 'insert into "personal_access_tokens"'],
        $exchange,
    );

    expect($first['status'])->toBe(200)->and($second['status'])->toBe(422)
        ->and($second['body']['errors'])->toHaveKey('code')
        ->and($user->tokens()->count())->toBe(1);
});

it('links and issues a fresh Google grant after the concurrent reset commits', function () {
    $user = User::factory()->unverified()->create();
    [$reset, $callback] = overlapCredentialRequests(
        [...concurrentResetFixture($user), 'pause_before' => 'delete from "personal_access_tokens"'],
        concurrentGoogleCallbackFixture($user->email, 'sign_in'),
    );

    expect($reset['status'])->toBe(200)->and($callback['status'])->toBe(302);
    parse_str(parse_url($callback['location'], PHP_URL_QUERY), $params);
    $this->postJson('/api/v1/auth/google/exchange', $params)
        ->assertOk()->assertJsonPath('user.id', $user->id);
    expect($user->fresh()->google_id)->toBe('synthetic-google-subject')
        ->and(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
});

it('ends a pending Google sign-in when its resolved account has been deleted', function () {
    $user = User::factory()->create(['password' => 'original-password', 'google_id' => 'synthetic-google-subject']);
    $emailMatch = User::factory()->unverified()->create();
    [$deleted, $callback] = overlapCredentialRequests(
        [...concurrentDeleteFixture($user), 'pause_before' => 'delete from "users"'],
        concurrentGoogleCallbackFixture($emailMatch->email, 'sign_in'),
    );

    expect($deleted['status'])->toBe(204)->and($callback['status'])->toBe(302)
        ->and($callback['location'])->toEndWith('?error=account_not_found');
    $this->assertModelMissing($user);
    expect($emailMatch->fresh()->google_id)->toBeNull()
        ->and($emailMatch->fresh()->email_verified_at)->toBeNull()
        ->and($emailMatch->fresh()->google_exchange_digest)->toBeNull();
});

it('serializes overlapping callbacks for different subjects at one email', function () {
    $user = User::factory()->unverified()->create();
    $callback = concurrentGoogleCallbackFixture($user->email, 'sign_in');
    [$first, $second] = overlapCredentialRequests(
        [...$callback, 'pause_before' => 'update "users"'],
        concurrentGoogleCallbackFixture($user->email, 'sign_in', 'second-synthetic-subject'),
    );

    expect($first['status'])->toBe(302)->and($second['location'])->toEndWith('?error=google_failed')
        ->and($user->fresh()->google_id)->toBe('synthetic-google-subject');
    parse_str(parse_url($first['location'], PHP_URL_QUERY), $params);
    $this->postJson('/api/v1/auth/google/exchange', $params)->assertOk()->assertJsonPath('user.id', $user->id);
});

it('serializes reset-link issuance and redemption against the current account', function (bool $resetWins) {
    $user = User::factory()->create(['password' => 'original-password']);
    $forgot = [...concurrentForgotFixture($user), 'pause_before' => 'insert into "password_reset_tokens"'];
    $reset = [...concurrentResetFixture($user), 'pause_before' => 'delete from "personal_access_tokens"'];
    DB::table('password_reset_tokens')->where('email', $user->email)->update(['created_at' => now()->subMinutes(2)]);

    if ($resetWins) {
        [$redeemed, $requested] = overlapCredentialRequests($reset, $forgot);
        expect($redeemed['status'])->toBe(200)
            ->and(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
    } else {
        [$requested, $redeemed] = overlapCredentialRequests($forgot, $reset);
        expect($redeemed['status'])->toBe(422)->and($redeemed['body']['errors'])->toHaveKey('token')
            ->and(Hash::check('original-password', $user->fresh()->password))->toBeTrue();
    }

    expect($requested['status'])->toBe(200);
    $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    expect(Password::tokenExists($user, $reset['body']['token']))->toBeFalse();
})->with(['redemption first' => true, 'issuance first' => false]);

it('leaves no recovery row when reset-link issuance overlaps account deletion', function (bool $deletionWins) {
    $user = User::factory()->create(['password' => 'original-password']);
    $forgot = [...concurrentForgotFixture($user), 'pause_before' => 'insert into "password_reset_tokens"'];
    $delete = [...concurrentDeleteFixture($user), 'pause_before' => 'delete from "users"'];

    // Only the winner pauses. The contender waits on the winner's account lock.
    if ($deletionWins) {
        unset($forgot['pause_before']);
        [$deleted, $requested] = overlapCredentialRequests($delete, $forgot);
    } else {
        unset($delete['pause_before']);
        [$requested, $deleted] = overlapCredentialRequests($forgot, $delete);
    }

    expect($requested['status'])->toBe(200)->and($deleted['status'])->toBe(204);
    $this->assertModelMissing($user);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
})->with(['deletion first' => true, 'issuance first' => false]);

it('rechecks a bearer before protected account mutations', function (string $method, string $path, array $body) {
    $user = User::factory()->unverified()->create(['name' => 'Before reset']);
    $bearer = $user->createToken('previous-session')->plainTextToken;
    [$reset, $mutation] = overlapCredentialRequests(
        [...concurrentResetFixture($user), 'pause_before' => 'delete from "personal_access_tokens"'],
        ['method' => $method, 'path' => $path, 'body' => $body, 'bearer' => $bearer],
    );

    expect($reset['status'])->toBe(200)->and($mutation['status'])->toBe(401)
        ->and($user->fresh()->name)->toBe('Before reset')
        ->and($user->fresh()->survey_done)->toBeFalse();
})->with([
    'profile edit' => ['PATCH', '/api/v1/me', ['name' => 'Other name']],
    'survey' => ['POST', '/api/v1/me/survey', []],
    'verification resend' => ['POST', '/api/v1/auth/email/resend', []],
    'logout' => ['POST', '/api/v1/auth/logout', []],
]);

it('allows an already-authorized read to complete while subsequent requests return 401', function () {
    $user = User::factory()->create();
    $bearer = $user->createToken('previous-session')->plainTextToken;
    $coordinator = DB::selectOne('select pg_backend_pid() as pid')->pid;
    DB::selectOne('select pg_advisory_lock(36036)');
    try {
        $read = credentialRequestProcess([
            'method' => 'GET',
            'path' => '/api/v1/me',
            'body' => [],
            'bearer' => $bearer,
            'pause_before' => 'update "personal_access_tokens"',
        ]);
        waitForCredentialRequestLock($read, $coordinator);
        $this->postJson('/api/v1/auth/password/reset', concurrentResetFixture($user)['body'])->assertOk();
        DB::selectOne('select pg_advisory_unlock(36036)');

        $response = credentialResponse($read);
        expect($response['status'])->toBe(200)->and($response['body']['data']['id'])->toBe($user->id);
        $this->withToken($bearer)->getJson('/api/v1/me')->assertUnauthorized();
    } finally {
        ($read ?? null)?->stop(0);
        DB::selectOne('select pg_advisory_unlock(36036)');
    }
});

it('commits one account and one initial bearer for overlapping signups', function (bool $googleContender) {
    $email = 'concurrent-registration@example.test';
    $register = [
        'method' => 'POST',
        'path' => '/api/v1/auth/register',
        'body' => ['name' => 'Synthetic User', 'email' => $email, 'password' => 'original-password', 'timezone' => 'UTC'],
    ];

    [$accepted, $rejected] = overlapCredentialRequests(
        [...$register, 'pause_before' => 'insert into "personal_access_tokens"'],
        $googleContender ? concurrentGoogleCallbackFixture($email, 'sign_up') : $register,
    );

    expect($accepted['status'])->toBe(201);
    if ($googleContender) {
        expect($rejected['status'])->toBe(302)->and($rejected['location'])->toEndWith('?error=account_already_exists');
    } else {
        expect($rejected['status'])->toBe(422)->and($rejected['body']['errors'])->toHaveKey('email');
    }

    $user = User::sole();
    expect($user->email)->toBe($email)->and($user->google_id)->toBeNull()
        ->and($user->tokens()->count())->toBe(1);
    $this->withToken($accepted['body']['token'])->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
})->with(['another email registration' => false, 'Google signup' => true]);

function googleDeletionRaceFixtures(User $user): array
{
    $bearer = $user->createToken('delete')->plainTextToken;
    $verifier = GoogleDeletionProvider::base64Url(random_bytes(32));
    $context = ['bearer' => $bearer, 'origin' => config('app.frontend_url')];
    $start = [
        ...$context,
        'method' => 'POST',
        'path' => '/api/v1/me/deletion/google/challenge',
        'body' => ['browser_challenge' => GoogleDeletionProvider::base64Url(hash('sha256', $verifier, true))],
    ];
    $started = credentialResponse(credentialRequestProcess($start));
    expect($started['status'])->toBe(201);
    $id = $started['body']['data']['id'];
    parse_str(parse_url($started['body']['data']['authorization_url'], PHP_URL_QUERY), $params);

    return [
        $start,
        [
            ...$context,
            'method' => 'POST',
            'path' => '/api/v1/me/deletion/google/verify',
            'body' => [
                'challenge_id' => $id, 'browser_verifier' => $verifier,
                'state' => $params['state'], 'code' => 'synthetic-code',
            ],
            'deletion_nonce' => $params['nonce'],
        ],
        [
            ...$context,
            'method' => 'DELETE',
            'path' => '/api/v1/me',
            'body' => ['google_confirmation' => ['challenge_id' => $id, 'browser_verifier' => $verifier]],
        ],
    ];
}

it('rechecks the reserved Google deletion attempt after a concurrent operation during code exchange', function (string $operation, int $expected) {
    $account = User::factory()->create(['password' => null, 'google_id' => 'linked-subject']);
    [$start, $verify, $final] = googleDeletionRaceFixtures($account);
    $id = $verify['body']['challenge_id'];
    $coordinator = DB::selectOne('select pg_backend_pid() as pid')->pid;
    DB::selectOne('select pg_advisory_lock(36036)');
    try {
        $request = credentialRequestProcess([...$verify, 'pause_exchange' => true]);
        waitForCredentialRequestLock($request, $coordinator);
        $this->assertDatabaseHas('google_deletion_challenges', ['id' => $id, 'status' => 'verifying', 'state_digest' => null]);

        match ($operation) {
            'duplicate verify' => expect(credentialResponse(credentialRequestProcess($verify))['status'])->toBe(422),
            'replacement' => expect(credentialResponse(credentialRequestProcess($start))['status'])->toBe(201),
            'logout' => expect(credentialResponse(credentialRequestProcess([
                ...$start, 'path' => '/api/v1/auth/logout', 'body' => [],
            ]))['status'])->toBe(204),
            'password reset' => expect(credentialResponse(credentialRequestProcess(concurrentResetFixture($account)))['status'])->toBe(200),
            'subject change' => DB::transaction(fn () => User::lockForUpdate()->findOrFail($account->id)
                ->forceFill(['google_id' => 'changed-subject'])->save()),
            'expiry' => GoogleDeletionChallenge::whereKey($id)->update(['expires_at' => now()->subSecond()]),
        };

        DB::selectOne('select pg_advisory_unlock(36036)');
        expect(credentialResponse($request)['status'])->toBe($expected);
        if ($operation === 'replacement') {
            $this->assertDatabaseMissing('google_deletion_challenges', ['id' => $id]);
            $this->assertDatabaseHas('google_deletion_challenges', ['user_id' => $account->id, 'status' => 'pending']);
        }
        if ($expected !== 200) {
            expect(credentialResponse(credentialRequestProcess($final))['status'])->toBe($expected);
        }
        $this->assertModelExists($account);
    } finally {
        ($request ?? null)?->stop(0);
        DB::selectOne('select pg_advisory_unlock(36036)');
    }
})->with([
    ['duplicate verify', 200], ['replacement', 422], ['logout', 401], ['password reset', 401], ['subject change', 422], ['expiry', 422],
]);

it('commits Google deletion once and serializes it with credential writers', function (string $contender, int $rejection) {
    $account = User::factory()->create(['password' => null, 'google_id' => 'linked-subject']);
    [, $verify, $final] = googleDeletionRaceFixtures($account);
    expect(credentialResponse(credentialRequestProcess($verify))['status'])->toBe(200);
    $existing = $account->createToken('another-session');
    $race = match ($contender) {
        'second delete' => $final,
        'logout' => [...$final, 'method' => 'POST', 'path' => '/api/v1/auth/logout', 'body' => []],
        'bearer issuance' => concurrentGoogleExchangeFixture($account),
    };

    [$deleted, $lost] = overlapCredentialRequests([...$final, 'pause_before' => 'delete from "personal_access_tokens"'], $race);

    expect($deleted['status'])->toBe(204)->and($lost['status'])->toBe($rejection);
    $this->assertModelMissing($account);
    $this->assertModelMissing($existing->accessToken);
    $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $account->id]);
    $this->assertDatabaseMissing('google_deletion_challenges', ['user_id' => $account->id]);
})->with([['second delete', 401], ['logout', 401], ['bearer issuance', 422]]);

it('enforces the Google challenge start limit across independent API processes', function () {
    $account = User::factory()->create(['password' => null, 'google_id' => 'linked-subject']);
    $bearer = $account->createToken('delete')->plainTextToken;
    $fixture = [
        'bearer' => $bearer,
        'origin' => config('app.frontend_url'),
        'cache_store' => 'database',
        'method' => 'POST',
        'path' => '/api/v1/me/deletion/google/challenge',
        'body' => ['browser_challenge' => GoogleDeletionProvider::base64Url(random_bytes(32))],
    ];
    $requests = [];
    try {
        foreach (range(1, 7) as $attempt) {
            $requests[] = credentialRequestProcess($fixture);
        }

        $statuses = array_column(array_map(credentialResponse(...), $requests), 'status');
        sort($statuses);
        expect($statuses)->toBe([201, 201, 201, 429, 429, 429, 429]);
        $this->assertDatabaseCount('google_deletion_challenges', 1);
    } finally {
        foreach ($requests as $request) {
            $request->stop(0);
        }
    }
});
