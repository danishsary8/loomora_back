<?php

use App\Models\FocusSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function softDeleteMigration(): object
{
    return require database_path('migrations/2026_11_26_000000_add_soft_deletes_to_focus_sessions_table.php');
}

function storedFocusSession(): string
{
    $id = (string) Str::uuid();
    test()->postJson('/api/v1/focus-sessions', [
        'id' => $id,
        'mode' => 'stopwatch',
        'started_at' => '2026-11-23T02:00:00.000Z',
        'ended_at' => '2026-11-23T02:01:00.000Z',
        'end_reason' => 'completed',
        'segments' => [
            ['type' => 'focus', 'started_at' => '2026-11-23T02:00:00.000Z', 'ended_at' => '2026-11-23T02:01:00.000Z', 'active_ms' => 60_000],
        ],
    ])->assertCreated();

    return $id;
}

beforeEach(function () {
    Carbon::setTestNow('2026-11-23 04:00:00');
    $this->actingAs(User::factory()->create(['timezone' => 'Asia/Phnom_Penh']), 'sanctum');
});

it('refuses to roll back while any session is deleted, and keeps the deletion', function () {
    $id = storedFocusSession();
    $this->deleteJson("/api/v1/focus-sessions/{$id}")->assertNoContent();

    expect(fn () => softDeleteMigration()->down())
        ->toThrow(RuntimeException::class, '1 focus session(s) are deleted');

    expect(Schema::hasColumn('focus_sessions', 'deleted_at'))->toBeTrue()
        ->and(FocusSession::withTrashed()->find($id)?->trashed())->toBeTrue();
});

it('drops only deleted_at when no session is deleted, and adds it back', function () {
    $id = storedFocusSession();
    $columns = Schema::getColumnListing('focus_sessions');

    $migration = softDeleteMigration();
    $migration->down();
    try {
        expect(Schema::getColumnListing('focus_sessions'))->toEqualCanonicalizing(array_values(array_diff($columns, ['deleted_at'])));
    } finally {
        $migration->up();
    }

    expect(Schema::getColumnListing('focus_sessions'))->toEqualCanonicalizing($columns)
        ->and(FocusSession::find($id))->not->toBeNull();
});
