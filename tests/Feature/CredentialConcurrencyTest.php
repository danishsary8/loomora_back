<?php

use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Process\Process;

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
        'CACHE_STORE' => $fixture['path'] === '/api/v1/auth/google/exchange' ? 'database' : 'array',
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
        $pid = (int) strtok($process->getOutput(), "\r\n");
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

        return array_map(function (Process $request): array {
            $request->wait();
            expect($request->isSuccessful())->toBeTrue();
            [, $response] = explode("\n", trim($request->getOutput()), 2);

            return json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        }, [$first, $second]);
    } finally {
        ($second ?? null)?->stop(0);
        ($first ?? null)?->stop(0);
        DB::selectOne('select pg_advisory_unlock(36036)');
    }
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
    $code = str_repeat('g', 64);
    Cache::store('database')->put('google-code:'.hash('sha256', $code), $user->id, 300);

    return ['method' => 'POST', 'path' => '/api/v1/auth/google/exchange', 'body' => ['code' => $code]];
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
