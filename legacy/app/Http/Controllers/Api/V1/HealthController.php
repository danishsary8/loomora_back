<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class HealthController extends Controller
{
    /**
     * Liveness probe, plus a one-word verdict on the database.
     *
     * Public and unauthenticated by design.
     *
     * Why the database is reported here
     * ---------------------------------
     * This endpoint used to answer `{"status":"ok"}` without touching anything,
     * which made it useless for the failure that actually happens in production:
     * the container boots, Apache serves, health says "ok", and every endpoint
     * that reads data returns 500 because the database host does not resolve.
     * With APP_DEBUG off the cause is invisible from outside, so diagnosing it
     * meant reading deploy logs.
     *
     * The status stays 200 even when the database is down. This path is wired to
     * Render's health check, and failing it would take a container that is
     * otherwise healthy out of service — losing the very endpoint that explains
     * what is wrong. It reports; it does not judge.
     *
     * Only coarse words are exposed — "unavailable", "missing". Driver messages
     * can carry hostnames and credentials, so they stay in the log.
     *
     * `schema` is reported separately from `database` because the two fail
     * independently and the difference decides what to do next. A connected
     * database with no tables answers `select 1` perfectly while every data
     * endpoint returns 500, which reads exactly like a broken API. That is the
     * state a deploy lands in whenever the migration step is skipped, and without
     * this field the only way to tell was reading container logs.
     */
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success([
            'status' => 'ok',
            ...$this->databaseState(),
        ]);
    }

    /**
     * @return array{database: string, schema: string}
     */
    private function databaseState(): array
    {
        try {
            DB::connection()->select('select 1');
        } catch (Throwable) {
            // No connection, so the schema is unknowable rather than missing.
            return ['database' => 'unavailable', 'schema' => 'unknown'];
        }

        try {
            $migrated = Schema::hasTable('migrations') && Schema::hasTable('users');

            return ['database' => 'ok', 'schema' => $migrated ? 'ok' : 'missing'];
        } catch (Throwable) {
            return ['database' => 'ok', 'schema' => 'unknown'];
        }
    }
}
