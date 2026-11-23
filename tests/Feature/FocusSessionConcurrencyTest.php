<?php

use App\Models\FocusSession;
use App\Models\User;
use App\Services\FocusSessionService;
use App\Support\FocusSessionPayload;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires independent PostgreSQL connections.');
    }
});

/** A finished stopwatch session that ended shortly before the real clock. */
function racingFocusSession(string $label): array
{
    $start = Carbon::now()->subMinutes(10)->startOfSecond();
    $end = $start->copy()->addMinutes(5);

    return [
        'id' => '0b6f7a52-8f4c-4c1e-9d5a-3e2f1a0b9c8d',
        'mode' => 'stopwatch',
        'started_at' => $start->toIso8601ZuluString(),
        'ended_at' => $end->toIso8601ZuluString(),
        'end_reason' => 'completed',
        'label' => $label,
        'segments' => [['type' => 'focus', 'started_at' => $start->toIso8601ZuluString(), 'ended_at' => $end->toIso8601ZuluString(), 'active_ms' => 300_000]],
    ];
}

/**
 * Holds the winning insert uncommitted in this connection, sends the same UUID from a second PHP
 * process, waits until PostgreSQL reports that process blocked on this one, then commits.
 */
function raceFocusSession(User $user, array $winner, array $contender): array
{
    $database = DB::connection()->getConfig();
    $process = new Process([PHP_BINARY, 'tests/Support/credential-request.php', json_encode([
        'method' => 'POST',
        'path' => '/api/v1/focus-sessions',
        'bearer' => $user->createToken('race')->plainTextToken,
        'body' => $contender,
    ], JSON_THROW_ON_ERROR)], dirname(__DIR__, 2), [
        'APP_ENV' => 'testing',
        'APP_DEBUG' => 'false',
        'APP_KEY' => config('app.key'),
        'CACHE_STORE' => 'array',
        'DB_CONNECTION' => 'pgsql',
        'DB_URL' => $database['url'] ?? '',
        'DB_HOST' => $database['host'],
        'DB_PORT' => (string) $database['port'],
        'DB_DATABASE' => $database['database'],
        'DB_USERNAME' => $database['username'],
        'DB_PASSWORD' => $database['password'],
        'SESSION_DRIVER' => 'array',
    ], timeout: 45);

    DB::beginTransaction();
    try {
        app(FocusSessionService::class)->record($user, FocusSessionPayload::fromInput($winner));
        $coordinator = DB::selectOne('select pg_backend_pid() as pid')->pid;

        $process->start();
        $deadline = microtime(true) + 30;
        do {
            usleep(20_000);
            $pid = (int) strtok($process->getOutput(), "\n");
            $blocked = $pid && DB::selectOne('select ? = any(pg_blocking_pids(?)) as blocked', [$coordinator, $pid])->blocked;
            if (! $blocked && ! $process->isRunning()) {
                throw new RuntimeException('The second request finished before it reached the conflicting insert.');
            }
        } while (! $blocked && microtime(true) < $deadline);

        expect($blocked)->toBeTrue();
        DB::commit();
        $process->wait();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $process->stop(0);
    }

    [, $response] = explode("\n", trim($process->getOutput()), 2);

    return json_decode($response, true, flags: JSON_THROW_ON_ERROR);
}

it('turns a concurrent identical retry into one session and a 200', function () {
    $user = User::factory()->create();
    $session = racingFocusSession('Essay');

    $response = raceFocusSession($user, $session, $session);

    expect($response['status'])->toBe(200)
        ->and($response['body']['data']['id'])->toBe($session['id'])
        ->and(FocusSession::count())->toBe(1);
});

it('answers 409 to a concurrent changed payload and keeps the winner', function () {
    $user = User::factory()->create();

    $response = raceFocusSession($user, racingFocusSession('Essay'), racingFocusSession('Something else'));

    expect($response['status'])->toBe(409)
        ->and(FocusSession::count())->toBe(1)
        ->and(FocusSession::first()->label)->toBe('Essay');
});
