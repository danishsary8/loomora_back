<?php

use App\Models\FocusSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** A finished 25-minute countdown with the given label. */
function labelledFocusSession(string $label): array
{
    return [
        'id' => (string) Str::uuid(),
        'mode' => 'countdown',
        'started_at' => '2026-11-23T02:00:00.000Z',
        'ended_at' => '2026-11-23T02:25:00.000Z',
        'end_reason' => 'completed',
        'planned_focus_ms' => 1_500_000,
        'label' => $label,
        'segments' => [
            ['type' => 'focus', 'started_at' => '2026-11-23T02:00:00.000Z', 'ended_at' => '2026-11-23T02:25:00.000Z', 'active_ms' => 1_500_000],
        ],
        'laps' => null,
    ];
}

function widenLabelMigration(): object
{
    return require database_path('migrations/2026_11_25_000000_widen_focus_session_label.php');
}

/** The declared length of focus_sessions.label, or null where the driver does not enforce one. */
function focusLabelLength(): ?int
{
    if (DB::getDriverName() !== 'pgsql') {
        return null;
    }

    return (int) DB::scalar(
        "select character_maximum_length from information_schema.columns where table_name = 'focus_sessions' and column_name = 'label'",
    );
}

beforeEach(function () {
    Carbon::setTestNow('2026-11-23 04:00:00');
    $this->actingAs(User::factory()->create(['timezone' => 'Asia/Phnom_Penh']), 'sanctum');
});

it('widens the label column to 200 characters', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('SQLite does not enforce varchar lengths.');
    }

    expect(focusLabelLength())->toBe(200)
        ->and(FocusSession::MAX_LABEL_LENGTH)->toBe(200);
});

it('refuses to roll back while any label is longer than 120 characters, and keeps every label', function () {
    $long = str_repeat('é', 150);
    $this->postJson('/api/v1/focus-sessions', labelledFocusSession($long))->assertCreated();

    expect(fn () => widenLabelMigration()->down())
        ->toThrow(RuntimeException::class, 'longer than 120 characters');

    expect(FocusSession::first()->label)->toBe($long);
    if (DB::getDriverName() === 'pgsql') {
        expect(focusLabelLength())->toBe(200);
    }
});

it('rolls back to 120 characters and up again when every label fits, without changing any label', function () {
    $fits = str_repeat('é', 120);
    $this->postJson('/api/v1/focus-sessions', labelledFocusSession($fits))->assertCreated();

    $migration = widenLabelMigration();
    $migration->down();
    try {
        expect(FocusSession::first()->label)->toBe($fits);
        if (DB::getDriverName() === 'pgsql') {
            expect(focusLabelLength())->toBe(120);
        }
    } finally {
        $migration->up();
    }

    expect(FocusSession::first()->label)->toBe($fits);
    if (DB::getDriverName() === 'pgsql') {
        expect(focusLabelLength())->toBe(200);
    }
});
