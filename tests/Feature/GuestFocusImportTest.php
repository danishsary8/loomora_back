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

it('skips an identical replay of an account-deleted session without restoring it', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');
    $session = guestFocusSession();
    $path = '/api/v1/focus-sessions/'.$session['id'];
    $body = ['focus_sessions' => [$session]];

    $this->postJson('/api/v1/import', $body)->assertOk()->assertJsonPath('data.focus_sessions.created', 1);
    $this->deleteJson($path)->assertNoContent();
    $deletedAt = FocusSession::withTrashed()->findOrFail($session['id'])->deleted_at;

    $this->postJson('/api/v1/import', $body)->assertOk()->assertJsonPath('data.focus_sessions.skipped', 1);
    $this->postJson('/api/v1/import', ['focus_sessions' => [[...$session, 'label' => 'Changed']]])->assertConflict();
    $this->getJson($path)->assertNotFound();
    expect(FocusSession::withTrashed()->findOrFail($session['id'])->deleted_at)->toEqual($deletedAt);
});

it('accepts exactly 500 distinct sessions in one authenticated request', function () {
    $sessions = array_map(fn () => guestFocusSession(), range(1, 500));
    $body = ['focus_sessions' => $sessions];
    $this->postJson('/api/v1/import', $body)->assertUnauthorized();

    $this->actingAs(User::factory()->create(), 'sanctum');
    $this->postJson('/api/v1/import', $body)->assertOk()->assertJsonPath('data.focus_sessions.created', 500);
    $this->assertDatabaseCount('focus_sessions', 500);
});

it('imports notes alongside linked Focus sessions and rolls back both with planner rows on a Focus conflict', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum');

    $payload = function () {
        $todoId = Str::uuid()->toString();
        $blockId = Str::uuid()->toString();

        return [
            'todos' => [['id' => $todoId, 'title' => 'Read']],
            'schedule_items' => [['id' => $blockId, 'title' => 'Study', 'start_time' => '08:00', 'end_time' => '09:00', 'days' => [0]]],
            'notes' => [['id' => Str::uuid()->toString(), 'body' => 'Keep this', 'schedule_item_id' => $blockId]],
            'focus_sessions' => [guestFocusSession(['todo_id' => $todoId])],
        ];
    };
    $body = $payload();
    $this->postJson('/api/v1/import', $body)->assertOk()
        ->assertJsonPath('data.todos.created', 1)
        ->assertJsonPath('data.schedule_items.created', 1)
        ->assertJsonPath('data.notes.created', 1)
        ->assertJsonPath('data.focus_sessions.created', 1);
    $this->postJson('/api/v1/import', $body)->assertOk()
        ->assertJsonPath('data.notes.skipped', 1)
        ->assertJsonPath('data.focus_sessions.skipped', 1);

    $retry = $payload();
    $retry['focus_sessions'][] = [...$body['focus_sessions'][0], 'label' => 'Conflicting replay'];
    $this->postJson('/api/v1/import', $retry)->assertConflict();

    foreach (['todos', 'schedule_items', 'notes', 'focus_sessions'] as $table) {
        $this->assertDatabaseMissing($table, ['id' => $retry[$table][0]['id']]);
        $this->assertDatabaseHas($table, ['id' => $body[$table][0]['id']]);
    }
});
