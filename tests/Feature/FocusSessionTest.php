<?php

use App\Models\FocusSession;
use App\Models\ScheduleItem;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const FOCUS_SESSION_ID = '6f1c2a8e-3b4d-4e5f-8a9b-0c1d2e3f4a5b';

/** A 25 + 5 + 25 minute countdown that started at 09:00 in Phnom Penh (02:00 UTC). */
function focusCountdown(array $overrides = []): array
{
    return [
        'id' => FOCUS_SESSION_ID,
        'mode' => 'countdown',
        'started_at' => '2026-11-23T02:00:00.000Z',
        'ended_at' => '2026-11-23T03:02:30.000Z',
        'end_reason' => 'completed',
        'planned_focus_ms' => 1_500_000,
        'todo_id' => null,
        'schedule_item_id' => null,
        'label' => 'Read chapter 4',
        'segments' => [
            ['type' => 'focus', 'started_at' => '2026-11-23T02:00:00.000Z', 'ended_at' => '2026-11-23T02:27:00.000Z', 'active_ms' => 1_500_000],
            ['type' => 'short_break', 'started_at' => '2026-11-23T02:27:00.000Z', 'ended_at' => '2026-11-23T02:32:00.000Z', 'active_ms' => 300_000],
            ['type' => 'focus', 'started_at' => '2026-11-23T02:32:00.000Z', 'ended_at' => '2026-11-23T03:02:30.000Z', 'active_ms' => 1_500_000],
        ],
        'laps' => null,
        ...$overrides,
    ];
}

/** A stopwatch run of 3 min 10.01 s with three laps. */
function focusStopwatch(array $overrides = []): array
{
    return [
        'id' => FOCUS_SESSION_ID,
        'mode' => 'stopwatch',
        'started_at' => '2026-11-23T02:00:00.000Z',
        'ended_at' => '2026-11-23T02:03:10.010Z',
        'end_reason' => 'completed',
        'segments' => [
            ['type' => 'focus', 'started_at' => '2026-11-23T02:00:00.000Z', 'ended_at' => '2026-11-23T02:03:10.010Z', 'active_ms' => 190_010],
        ],
        'laps' => [61_230, 125_470, 190_010],
        ...$overrides,
    ];
}

beforeEach(function () {
    Carbon::setTestNow('2026-11-23 04:00:00');
    $this->user = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
    $this->actingAs($this->user, 'sanctum');
});

describe('recording', function () {
    it('stores a countdown with break cycles and returns 201', function () {
        $this->postJson('/api/v1/focus-sessions', focusCountdown())
            ->assertCreated()
            ->assertJsonPath('data.id', FOCUS_SESSION_ID)
            ->assertJsonPath('data.mode', 'countdown')
            ->assertJsonPath('data.started_at', '2026-11-23T02:00:00.000Z')
            ->assertJsonPath('data.ended_at', '2026-11-23T03:02:30.000Z')
            ->assertJsonPath('data.local_date', '2026-11-23')
            ->assertJsonPath('data.planned_focus_ms', 1_500_000)
            ->assertJsonPath('data.focus_ms', 3_000_000)
            ->assertJsonPath('data.break_ms', 300_000)
            ->assertJsonPath('data.paused_ms', 450_000)
            ->assertJsonPath('data.label', 'Read chapter 4')
            ->assertJsonPath('data.segments.1.type', 'short_break')
            ->assertJsonPath('data.segments.2.ended_at', '2026-11-23T03:02:30.000Z')
            ->assertJsonPath('data.laps', null);

        expect(FocusSession::count())->toBe(1)
            ->and(FocusSession::first()->user_id)->toBe($this->user->id);
    });

    it('stores a stopwatch with laps in milliseconds', function () {
        $this->postJson('/api/v1/focus-sessions', focusStopwatch())
            ->assertCreated()
            ->assertJsonPath('data.mode', 'stopwatch')
            ->assertJsonPath('data.planned_focus_ms', null)
            ->assertJsonPath('data.focus_ms', 190_010)
            ->assertJsonPath('data.paused_ms', 0)
            ->assertJsonPath('data.ended_at', '2026-11-23T02:03:10.010Z')
            ->assertJsonPath('data.laps', [61_230, 125_470, 190_010]);
    });

    it('keeps the exact milliseconds of an early finish under 60 seconds', function () {
        $this->postJson('/api/v1/focus-sessions', focusCountdown([
            'ended_at' => '2026-11-23T02:00:01.500Z',
            'end_reason' => 'ended_early',
            'segments' => [['type' => 'focus', 'started_at' => '2026-11-23T02:00:00.000Z', 'ended_at' => '2026-11-23T02:00:01.500Z', 'active_ms' => 1_234]],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.end_reason', 'ended_early')
            ->assertJsonPath('data.focus_ms', 1_234)
            ->assertJsonPath('data.paused_ms', 266);
    });

    it('rejects a session with no active Focus time', function (array $segments) {
        $this->postJson('/api/v1/focus-sessions', focusCountdown(['segments' => $segments]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['segments' => __('focus.no_focus_time')]);

        expect(FocusSession::count())->toBe(0);
    })->with([
        'zero active focus' => [[['type' => 'focus', 'started_at' => '2026-11-23T02:00:00.000Z', 'ended_at' => '2026-11-23T02:10:00.000Z', 'active_ms' => 0]]],
        'only a break' => [[['type' => 'long_break', 'started_at' => '2026-11-23T02:00:00.000Z', 'ended_at' => '2026-11-23T02:15:00.000Z', 'active_ms' => 900_000]]],
    ]);

    it('never counts pauses or breaks as Focus time', function () {
        $this->postJson('/api/v1/focus-sessions', focusCountdown([
            'segments' => [
                ['type' => 'focus', 'started_at' => '2026-11-23T02:00:00.000Z', 'ended_at' => '2026-11-23T02:40:00.000Z', 'active_ms' => 1_500_000],
                ['type' => 'long_break', 'started_at' => '2026-11-23T02:40:00.000Z', 'ended_at' => '2026-11-23T03:00:00.000Z', 'active_ms' => 900_000],
            ],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.focus_ms', 1_500_000)
            ->assertJsonPath('data.break_ms', 900_000)
            ->assertJsonPath('data.paused_ms', 3_750_000 - 1_500_000 - 900_000);
    });
});

describe('idempotency', function () {
    it('returns the stored session with 200 for an identical retry', function () {
        $first = $this->postJson('/api/v1/focus-sessions', focusCountdown())->assertCreated()->json('data');

        Carbon::setTestNow('2026-11-23 05:00:00');
        $this->postJson('/api/v1/focus-sessions', focusCountdown())
            ->assertOk()
            ->assertExactJson(['data' => $first]);

        expect(FocusSession::count())->toBe(1);
    });

    it('treats the same instants and UUIDs written differently as identical', function () {
        $this->postJson('/api/v1/focus-sessions', focusCountdown())->assertCreated();

        $this->postJson('/api/v1/focus-sessions', focusCountdown([
            'id' => strtoupper(FOCUS_SESSION_ID),
            'started_at' => '2026-11-23T09:00:00+07:00',
            'ended_at' => '2026-11-23T03:02:30Z',
        ]))->assertOk()->assertJsonPath('data.id', FOCUS_SESSION_ID);

        expect(FocusSession::count())->toBe(1);
    });

    it('answers 409 for a changed payload and keeps the stored session', function (array $change) {
        config(['app.debug' => false]);
        $this->postJson('/api/v1/focus-sessions', focusCountdown())->assertCreated();

        $this->postJson('/api/v1/focus-sessions', focusCountdown($change))
            ->assertConflict()
            ->assertExactJson(['message' => __('focus.conflict')]);

        expect(FocusSession::count())->toBe(1)
            ->and(FocusSession::first()->focus_ms)->toBe(3_000_000)
            ->and(FocusSession::first()->label)->toBe('Read chapter 4');
    })->with([
        'label' => [['label' => 'Something else']],
        'end reason' => [['end_reason' => 'ended_early']],
        'ended at' => [['ended_at' => '2026-11-23T03:02:31.000Z']],
    ]);

    it('answers the same 409 for another account\'s UUID without revealing it', function () {
        config(['app.debug' => false]);
        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')->postJson('/api/v1/focus-sessions', focusCountdown())->assertCreated();

        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/focus-sessions', focusCountdown())
            ->assertConflict()
            ->assertExactJson(['message' => __('focus.conflict')]);

        expect(FocusSession::count())->toBe(1)
            ->and(FocusSession::first()->user_id)->toBe($other->id);
    });

    it('ignores a client user_id', function () {
        $other = User::factory()->create();

        $this->postJson('/api/v1/focus-sessions', focusCountdown(['user_id' => $other->id]))->assertCreated();

        expect(FocusSession::first()->user_id)->toBe($this->user->id);
    });
});

describe('reading', function () {
    it('shows one of the user\'s sessions', function () {
        $this->postJson('/api/v1/focus-sessions', focusCountdown())->assertCreated();

        $this->getJson('/api/v1/focus-sessions/'.FOCUS_SESSION_ID)
            ->assertOk()
            ->assertJsonPath('data.id', FOCUS_SESSION_ID)
            ->assertJsonPath('data.focus_ms', 3_000_000);
    });

    it('answers 404 for an unknown session and for another user\'s session', function () {
        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')->postJson('/api/v1/focus-sessions', focusCountdown())->assertCreated();

        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/focus-sessions/'.FOCUS_SESSION_ID)->assertNotFound();
        $this->getJson('/api/v1/focus-sessions/'.Str::uuid())->assertNotFound();
    });

    it('lists one local day of the user\'s own sessions, oldest first', function () {
        $other = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
        $this->actingAs($other, 'sanctum')->postJson('/api/v1/focus-sessions', focusCountdown(['id' => (string) Str::uuid()]))->assertCreated();

        $this->actingAs($this->user, 'sanctum');
        $later = (string) Str::uuid();
        $this->postJson('/api/v1/focus-sessions', focusStopwatch([
            'id' => $later,
            'started_at' => '2026-11-23T03:30:00.000Z',
            'ended_at' => '2026-11-23T03:33:10.010Z',
            'segments' => [['type' => 'focus', 'started_at' => '2026-11-23T03:30:00.000Z', 'ended_at' => '2026-11-23T03:33:10.010Z', 'active_ms' => 190_010]],
        ]))->assertCreated();
        $this->postJson('/api/v1/focus-sessions', focusCountdown())->assertCreated();

        $this->getJson('/api/v1/focus-sessions?date=2026-11-23')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', FOCUS_SESSION_ID)
            ->assertJsonPath('data.1.id', $later);

        $this->getJson('/api/v1/focus-sessions?date=2026-11-22')->assertOk()->assertExactJson(['data' => []]);
    });

    it('needs a valid date for the list', function (?string $date) {
        $this->getJson('/api/v1/focus-sessions'.($date === null ? '' : '?date='.$date))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    })->with([null, '23-11-2026', '2026-13-01']);

    it('requires authentication on every route', function () {
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/focus-sessions', focusCountdown())->assertUnauthorized();
        $this->getJson('/api/v1/focus-sessions?date=2026-11-23')->assertUnauthorized();
        $this->getJson('/api/v1/focus-sessions/'.FOCUS_SESSION_ID)->assertUnauthorized();
    });
});

describe('local date', function () {
    it('puts a session that crosses local midnight on the day it started', function () {
        $this->postJson('/api/v1/focus-sessions', focusStopwatch([
            'started_at' => '2026-11-22T16:30:00.000Z',
            'ended_at' => '2026-11-22T17:30:00.000Z',
            'segments' => [['type' => 'focus', 'started_at' => '2026-11-22T16:30:00.000Z', 'ended_at' => '2026-11-22T17:30:00.000Z', 'active_ms' => 3_600_000]],
            'laps' => null,
        ]))->assertCreated()->assertJsonPath('data.local_date', '2026-11-22');

        $this->getJson('/api/v1/focus-sessions?date=2026-11-22')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/focus-sessions?date=2026-11-23')->assertJsonCount(0, 'data');
    });

    it('uses the account\'s own time zone', function () {
        $this->user->update(['timezone' => 'America/New_York']);

        $this->postJson('/api/v1/focus-sessions', focusCountdown())
            ->assertCreated()
            ->assertJsonPath('data.local_date', '2026-11-22');
    });
});

describe('links', function () {
    it('links an owned to-do and keeps the link after the to-do is deleted', function () {
        $todo = Todo::factory()->for($this->user)->create();

        $this->postJson('/api/v1/focus-sessions', focusCountdown(['todo_id' => $todo->id]))
            ->assertCreated()
            ->assertJsonPath('data.todo_id', $todo->id);

        $this->deleteJson("/api/v1/todos/{$todo->id}")->assertNoContent();

        $this->getJson('/api/v1/focus-sessions/'.FOCUS_SESSION_ID)->assertJsonPath('data.todo_id', $todo->id);
    });

    it('accepts an owned to-do that was already deleted, for an offline queue', function () {
        $todo = Todo::factory()->for($this->user)->create();
        $todo->delete();

        $this->postJson('/api/v1/focus-sessions', focusCountdown(['todo_id' => $todo->id]))
            ->assertCreated()
            ->assertJsonPath('data.todo_id', $todo->id);
    });

    it('keeps the session and its label when the linked schedule item is deleted', function () {
        $item = ScheduleItem::factory()->for($this->user)->create();

        $this->postJson('/api/v1/focus-sessions', focusCountdown(['schedule_item_id' => $item->id]))
            ->assertCreated()
            ->assertJsonPath('data.schedule_item_id', $item->id);

        $this->deleteJson("/api/v1/schedule/items/{$item->id}")->assertNoContent();

        $this->getJson('/api/v1/focus-sessions/'.FOCUS_SESSION_ID)
            ->assertOk()
            ->assertJsonPath('data.schedule_item_id', null)
            ->assertJsonPath('data.label', 'Read chapter 4')
            ->assertJsonPath('data.focus_ms', 3_000_000);

        // A retry of the original request is still the same session.
        $this->postJson('/api/v1/focus-sessions', focusCountdown(['schedule_item_id' => $item->id]))->assertOk();
    });

    it('rejects a link that is unknown or belongs to another account, with the same message', function (string $field, Closure $makeOther) {
        $foreign = $makeOther(User::factory()->create())->id;

        foreach ([$foreign, (string) Str::uuid()] as $id) {
            $this->postJson('/api/v1/focus-sessions', focusCountdown([$field => $id]))
                ->assertUnprocessable()
                ->assertExactJson(['message' => __('focus.link_not_found'), 'errors' => [$field => [__('focus.link_not_found')]]]);
        }

        expect(FocusSession::count())->toBe(0);
    })->with([
        'to-do' => ['todo_id', fn (User $owner) => Todo::factory()->for($owner)->create()],
        'schedule item' => ['schedule_item_id', fn (User $owner) => ScheduleItem::factory()->for($owner)->create()],
    ]);

    it('rejects both links at once', function () {
        $this->postJson('/api/v1/focus-sessions', focusCountdown([
            'todo_id' => Todo::factory()->for($this->user)->create()->id,
            'schedule_item_id' => ScheduleItem::factory()->for($this->user)->create()->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('todo_id');
    });
});

describe('validation', function () {
    it('rejects an invalid session', function (Closure $payload, string $field) {
        $this->postJson('/api/v1/focus-sessions', $payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        expect(FocusSession::count())->toBe(0);
    })->with([
        'missing id' => [fn () => focusCountdown(['id' => null]), 'id'],
        'id not a UUID' => [fn () => focusCountdown(['id' => 'abc']), 'id'],
        'unknown mode' => [fn () => focusCountdown(['mode' => 'pomodoro']), 'mode'],
        'unknown end reason' => [fn () => focusCountdown(['end_reason' => 'abandoned']), 'end_reason'],
        'timestamp without offset' => [fn () => focusCountdown(['started_at' => '2026-11-23T02:00:00']), 'started_at'],
        'end before start' => [fn () => focusCountdown(['ended_at' => '2026-11-23T01:59:59.000Z']), 'ended_at'],
        'end in the future' => [fn () => focusCountdown(['ended_at' => '2026-11-23T04:05:00.001Z']), 'ended_at'],
        'countdown without planned time' => [fn () => focusCountdown(['planned_focus_ms' => null]), 'planned_focus_ms'],
        'planned time of zero' => [fn () => focusCountdown(['planned_focus_ms' => 0]), 'planned_focus_ms'],
        'stopwatch with planned time' => [fn () => focusStopwatch(['planned_focus_ms' => 60_000]), 'planned_focus_ms'],
        'countdown with laps' => [fn () => focusCountdown(['laps' => [1_000]]), 'laps'],
        'stopwatch with a break' => [fn () => focusStopwatch(['segments' => [
            ['type' => 'focus', 'started_at' => '2026-11-23T02:00:00.000Z', 'ended_at' => '2026-11-23T02:01:00.000Z', 'active_ms' => 60_000],
            ['type' => 'short_break', 'started_at' => '2026-11-23T02:01:00.000Z', 'ended_at' => '2026-11-23T02:02:00.000Z', 'active_ms' => 60_000],
        ], 'laps' => null]), 'segments.1.type'],
        'laps not increasing' => [fn () => focusStopwatch(['laps' => [61_230, 61_230]]), 'laps'],
        'lap past the Focus time' => [fn () => focusStopwatch(['laps' => [190_011]]), 'laps'],
        'lap of zero' => [fn () => focusStopwatch(['laps' => [0]]), 'laps.0'],
        'too many laps' => [fn () => focusStopwatch(['laps' => range(1, FocusSession::MAX_LAPS + 1)]), 'laps'],
        'no segments' => [fn () => focusCountdown(['segments' => []]), 'segments'],
        'too many segments' => [fn () => focusCountdown(['segments' => array_fill(0, FocusSession::MAX_SEGMENTS + 1, focusCountdown()['segments'][0])]), 'segments'],
        'unknown segment type' => [fn () => focusCountdown(['segments' => [['type' => 'nap'] + focusCountdown()['segments'][0]]]), 'segments.0.type'],
        'extra segment key' => [fn () => focusCountdown(['segments' => [focusCountdown()['segments'][0] + ['paused_ms' => 1]]]), 'segments.0'],
        'negative active time' => [fn () => focusCountdown(['segments' => [['active_ms' => -1] + focusCountdown()['segments'][0]]]), 'segments.0.active_ms'],
        'active longer than the segment' => [fn () => focusCountdown(['segments' => [['active_ms' => 1_620_001] + focusCountdown()['segments'][0]]]), 'segments.0.active_ms'],
        'segment before the session' => [fn () => focusCountdown(['segments' => [['started_at' => '2026-11-23T01:59:59.999Z'] + focusCountdown()['segments'][0]]]), 'segments.0'],
        'segment after the session' => [fn () => focusCountdown(['ended_at' => '2026-11-23T02:20:00.000Z', 'segments' => [focusCountdown()['segments'][0]]]), 'segments.0'],
        'overlapping segments' => [fn () => focusCountdown(['segments' => [
            focusCountdown()['segments'][0],
            ['type' => 'short_break', 'started_at' => '2026-11-23T02:26:59.999Z', 'ended_at' => '2026-11-23T02:32:00.000Z', 'active_ms' => 300_000],
        ]]), 'segments.1'],
        'segment ending before it starts' => [fn () => focusCountdown(['segments' => [['ended_at' => '2026-11-23T01:00:00.000Z'] + focusCountdown()['segments'][0]]]), 'segments.0'],
        'label too long' => [fn () => focusCountdown(['label' => str_repeat('a', FocusSession::MAX_LABEL_LENGTH + 1)]), 'label'],
        'todo id not a UUID' => [fn () => focusCountdown(['todo_id' => '42']), 'todo_id'],
    ]);
});

describe('integrity', function () {
    it('removes the user\'s sessions with the account', function () {
        $todo = Todo::factory()->for($this->user)->create();
        $this->postJson('/api/v1/focus-sessions', focusCountdown(['todo_id' => $todo->id]))->assertCreated();
        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')->postJson('/api/v1/focus-sessions', focusCountdown(['id' => (string) Str::uuid()]))->assertCreated();

        $this->user->deleteWithData();

        expect(FocusSession::count())->toBe(1)
            ->and(FocusSession::first()->user_id)->toBe($other->id);
    });

    it('writes nothing when a link check fails inside the transaction', function () {
        $item = ScheduleItem::factory()->for($this->user)->create();
        $item->delete();

        $this->postJson('/api/v1/focus-sessions', focusCountdown(['schedule_item_id' => $item->id]))->assertUnprocessable();

        expect(FocusSession::count())->toBe(0)
            ->and(DB::transactionLevel())->toBe(1);
    });

    it('enforces the session invariants in PostgreSQL itself', function (array $change) {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The check constraints exist only on PostgreSQL.');
        }

        $this->postJson('/api/v1/focus-sessions', focusCountdown())->assertCreated();
        $row = (array) DB::table('focus_sessions')->first();

        expect(fn () => DB::transaction(fn () => DB::table('focus_sessions')->insert([...$row, 'id' => (string) Str::uuid(), ...$change])))
            ->toThrow(QueryException::class);
    })->with([
        'zero focus' => [['focus_ms' => 0]],
        'unknown mode' => [['mode' => 'pomodoro']],
        'end before start' => [['ended_at' => '2026-11-23 01:00:00+00']],
        'unknown end reason' => [['end_reason' => 'abandoned']],
        'negative paused time' => [['paused_ms' => -1]],
    ]);
});
