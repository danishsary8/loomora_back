<?php

use App\Models\FocusSession;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function guestFocusSession(array $overrides = []): array
{
    return [
        'id' => Str::uuid()->toString(),
        'mode' => 'stopwatch',
        'end_reason' => 'completed',
        'started_at' => '2026-11-23T02:00:00.000Z',
        'ended_at' => '2026-11-23T02:01:30.000Z',
        'segments' => [
            ['type' => 'focus', 'started_at' => '2026-11-23T02:00:00.000Z', 'ended_at' => '2026-11-23T02:01:30.000Z', 'active_ms' => 90000],
        ],
        ...$overrides,
    ];
}

it('imports a Focus session linked to a guest to-do and skips an identical replay', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'Asia/Phnom_Penh']), 'sanctum');
    $todo = ['id' => Str::uuid()->toString(), 'title' => 'Read'];
    $session = guestFocusSession(['todo_id' => $todo['id']]);
    $body = ['todos' => [$todo], 'focus_sessions' => [$session]];

    $this->postJson('/api/v1/import', $body)->assertOk()->assertJsonPath('data.focus_sessions.created', 1);
    $this->postJson('/api/v1/import', $body)->assertOk()->assertJsonPath('data.focus_sessions.skipped', 1);
    $this->getJson('/api/v1/focus-sessions/'.$session['id'])->assertOk()
        ->assertJsonPath('data.todo_id', $todo['id'])->assertJsonPath('data.focus_ms', 90000);
    $this->assertDatabaseCount('focus_sessions', 1);
});

it('rejects invalid or deleted guest sessions without writing other import sections', function (array $overrides, string $field) {
    $this->actingAs(User::factory()->create(), 'sanctum');
    $body = [
        'todos' => [['id' => Str::uuid()->toString(), 'title' => 'Read']],
        'focus_sessions' => [guestFocusSession($overrides)],
    ];
    $this->postJson('/api/v1/import', $body)->assertUnprocessable()->assertJsonValidationErrors("focus_sessions.0.{$field}");
    expect(Todo::count())->toBe(0)->and(FocusSession::count())->toBe(0);
})->with([
    [['ended_at' => '2026-11-23T01:00:00.000Z'], 'ended_at'],
    [['deleted_at' => '2026-11-24T02:00:00.000Z'], 'deleted_at'],
    [['todo_id' => '91daaeac-3913-4d52-bfa0-02febcdbd561'], 'todo_id'],
]);

it('rolls back a Focus import with an ID collision and isolates accounts', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $session = guestFocusSession();
    $this->actingAs($owner, 'sanctum')->postJson('/api/v1/focus-sessions', $session)->assertCreated();
    $this->actingAs($other, 'sanctum')->postJson('/api/v1/import', [
        'focus_sessions' => [guestFocusSession(), $session],
    ])->assertConflict();
    expect(FocusSession::count())->toBe(1);
    $this->assertDatabaseHas('focus_sessions', ['id' => $session['id'], 'user_id' => $owner->id]);
});

it('enforces the Focus import chunk boundary and unique client IDs', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');
    $session = guestFocusSession();
    $this->postJson('/api/v1/import', ['focus_sessions' => [$session, ['id' => strtoupper($session['id']), ...array_diff_key($session, ['id' => true])]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('focus_sessions.0.id');

    $this->postJson('/api/v1/import', ['focus_sessions' => array_fill(0, 501, ['id' => $session['id']])])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('focus_sessions');

    $this->assertDatabaseCount('focus_sessions', 0);
});
