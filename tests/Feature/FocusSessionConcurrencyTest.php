<?php

use App\Models\FocusSession;
use App\Models\User;
use App\Services\FocusSessionService;
use App\Support\FocusSessionPayload;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\RowLockRace;

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

/** Holds the winning insert uncommitted while the same UUID arrives from a second process. */
function raceFocusSession(User $user, array $winner, array $contender): array
{
    return RowLockRace::run($user, 'POST', '/api/v1/focus-sessions', $contender,
        fn () => app(FocusSessionService::class)->record($user, FocusSessionPayload::fromInput($winner)));
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
