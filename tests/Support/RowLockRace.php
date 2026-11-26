<?php

namespace Tests\Support;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs `$winner` in an uncommitted transaction on this connection, sends the contending request from
 * a second PHP process, waits until PostgreSQL reports that process blocked on this one, then commits.
 */
class RowLockRace
{
    /**
     * @param  array<string, mixed>  $body
     * @return array{status: int, body: mixed}
     */
    public static function run(User $user, string $method, string $path, array $body, Closure $winner): array
    {
        $database = DB::connection()->getConfig();
        $process = new Process([PHP_BINARY, 'tests/Support/credential-request.php', json_encode([
            'method' => $method,
            'path' => $path,
            'bearer' => $user->createToken('race')->plainTextToken,
            'body' => $body,
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
            $winner();
            $coordinator = DB::selectOne('select pg_backend_pid() as pid')->pid;

            $process->start();
            $deadline = microtime(true) + 30;
            do {
                usleep(20_000);
                $pid = (int) strtok($process->getOutput(), "\n");
                $blocked = $pid && DB::selectOne('select ? = any(pg_blocking_pids(?)) as blocked', [$coordinator, $pid])->blocked;
                if (! $blocked && ! $process->isRunning()) {
                    throw new RuntimeException('The second request finished before it reached the locked row.');
                }
            } while (! $blocked && microtime(true) < $deadline);

            if (! $blocked) {
                throw new RuntimeException('The second request never waited for the locked row.');
            }
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
}
