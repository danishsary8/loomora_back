<?php

use App\Models\ScheduleCheckin;
use App\Models\ScheduleException;
use App\Models\ScheduleItem;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-26 03:00:00'); // Monday 10:00 in Phnom Penh
    $this->user = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
    $this->actingAs($this->user, 'sanctum');
});

function guestPayload(array $overrides = []): array
{
    $item = (string) Str::uuid();

    return array_replace([
        'todos' => [
            ['id' => (string) Str::uuid(), 'title' => 'Buy pens', 'category' => 'life', 'due_date' => '2026-10-26', 'done_at' => null],
            ['id' => (string) Str::uuid(), 'title' => 'Read ch. 3', 'due_date' => null, 'done_at' => '2026-10-25T09:00:00Z', 'created_at' => '2026-10-20T08:00:00Z'],
        ],
        'schedule_items' => [
            ['id' => $item, 'title' => 'Math', 'category' => 'study', 'start_time' => '08:00', 'end_time' => '09:30', 'days' => [0, 2], 'starts_on' => '2026-10-01', 'ends_on' => null],
        ],
        'exceptions' => [
            ['id' => (string) Str::uuid(), 'schedule_item_id' => $item, 'date' => '2026-10-28', 'type' => 'skip'],
            ['id' => (string) Str::uuid(), 'schedule_item_id' => $item, 'date' => '2026-10-26', 'type' => 'override', 'title' => 'Exam', 'start_time' => '10:00', 'end_time' => '12:00'],
        ],
        'checkins' => [
            ['id' => (string) Str::uuid(), 'schedule_item_id' => $item, 'date' => '2026-10-21', 'done_at' => '2026-10-21T02:00:00Z'],
        ],
    ], $overrides);
}

function nothingWritten(): bool
{
    return Todo::count() + ScheduleItem::count() + ScheduleException::count() + ScheduleCheckin::count() === 0;
}

describe('success', function () {
    it('imports everything and keeps the client ids', function () {
        $payload = guestPayload();

        $this->postJson('/api/v1/import', $payload)
            ->assertOk()
            ->assertJsonPath('data.todos', ['created' => 2, 'skipped' => 0])
            ->assertJsonPath('data.schedule_items', ['created' => 1, 'skipped' => 0])
            ->assertJsonPath('data.exceptions', ['created' => 2, 'skipped' => 0])
            ->assertJsonPath('data.checkins', ['created' => 1, 'skipped' => 0]);

        expect(Todo::count())->toBe(2)
            ->and(Todo::find($payload['todos'][0]['id'])->user_id)->toBe($this->user->id)
            ->and(Todo::find($payload['todos'][0]['id'])->category)->toBe('life')
            ->and(Todo::find($payload['todos'][1]['id'])->category)->toBe('other')
            ->and(Todo::find($payload['todos'][1]['id'])->done_at)->not->toBeNull()
            ->and(Todo::find($payload['todos'][1]['id'])->created_at->toDateString())->toBe('2026-10-20')
            ->and(ScheduleItem::find($payload['schedule_items'][0]['id'])->days)->toBe([0, 2])
            ->and(ScheduleException::find($payload['exceptions'][1]['id'])->title)->toBe('Exam')
            ->and(ScheduleCheckin::find($payload['checkins'][0]['id'])->date)->toBe('2026-10-21');
    });

    it('makes imported data visible through the normal endpoints', function () {
        $this->postJson('/api/v1/import', guestPayload())->assertOk();

        $this->getJson('/api/v1/schedule/day?date=2026-10-26')
            ->assertJsonPath('data.0.title', 'Exam')
            ->assertJsonPath('data.0.start_time', '10:00');
        $this->getJson('/api/v1/schedule/day?date=2026-10-28')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/todos?date=2026-10-26')->assertJsonCount(1, 'data'); // dated today; the undated one is done
    });

    it('accepts an empty or partial payload', function () {
        $this->postJson('/api/v1/import', [])->assertOk()->assertJsonPath('data.todos.created', 0);
        $this->postJson('/api/v1/import', ['todos' => [['id' => (string) Str::uuid(), 'title' => 'Only this']]])
            ->assertOk()->assertJsonPath('data.todos.created', 1);
    });

    it('defaults starts_on to today in the user time zone', function () {
        $payload = guestPayload(['exceptions' => [], 'checkins' => []]);
        unset($payload['schedule_items'][0]['starts_on']);

        $this->postJson('/api/v1/import', $payload)->assertOk();

        expect(ScheduleItem::first()->starts_on->toDateString())->toBe('2026-10-26');
    });

    it('recomputes the streak from imported check-offs', function () {
        $payload = guestPayload(['exceptions' => [], 'checkins' => []]);
        $payload['todos'][0]['done_at'] = '2026-10-26T01:00:00Z'; // due and done today

        $this->postJson('/api/v1/import', $payload)->assertOk();

        expect($this->user->refresh()->current_streak)->toBeGreaterThanOrEqual(1);
    });
});

describe('idempotent', function () {
    it('creates no duplicates when the same payload is sent again', function () {
        $payload = guestPayload();

        $this->postJson('/api/v1/import', $payload)->assertOk();
        $this->postJson('/api/v1/import', $payload)
            ->assertOk()
            ->assertJsonPath('data.todos', ['created' => 0, 'skipped' => 2])
            ->assertJsonPath('data.schedule_items', ['created' => 0, 'skipped' => 1])
            ->assertJsonPath('data.exceptions', ['created' => 0, 'skipped' => 2])
            ->assertJsonPath('data.checkins', ['created' => 0, 'skipped' => 1]);

        expect(Todo::count())->toBe(2)->and(ScheduleItem::count())->toBe(1)
            ->and(ScheduleException::count())->toBe(2)->and(ScheduleCheckin::count())->toBe(1);
    });

    it('does not overwrite a row the server already changed', function () {
        $payload = guestPayload();
        $this->postJson('/api/v1/import', $payload)->assertOk();
        Todo::find($payload['todos'][0]['id'])->update(['title' => 'Edited on the server']);

        $this->postJson('/api/v1/import', $payload)->assertOk();

        expect(Todo::find($payload['todos'][0]['id'])->title)->toBe('Edited on the server');
    });

    it('skips an exception or check-in for a day that already has one with another id', function () {
        $payload = guestPayload();
        $this->postJson('/api/v1/import', $payload)->assertOk();

        $again = guestPayload(['todos' => [], 'schedule_items' => []]);
        $again['exceptions'] = [['id' => (string) Str::uuid(), 'schedule_item_id' => $payload['schedule_items'][0]['id'], 'date' => '2026-10-28', 'type' => 'skip']];
        $again['checkins'] = [['id' => (string) Str::uuid(), 'schedule_item_id' => $payload['schedule_items'][0]['id'], 'date' => '2026-10-21']];

        $this->postJson('/api/v1/import', $again)->assertOk()
            ->assertJsonPath('data.exceptions.created', 0)->assertJsonPath('data.checkins.created', 0);

        expect(ScheduleException::count())->toBe(2)->and(ScheduleCheckin::count())->toBe(1);
    });

    it('lets later imports add rows to items imported earlier', function () {
        $payload = guestPayload();
        $this->postJson('/api/v1/import', $payload)->assertOk();

        $this->postJson('/api/v1/import', [
            'checkins' => [['id' => (string) Str::uuid(), 'schedule_item_id' => $payload['schedule_items'][0]['id'], 'date' => '2026-10-26']],
        ])->assertOk()->assertJsonPath('data.checkins.created', 1);
    });
});

describe('invalid payload writes nothing', function () {
    it('rejects one bad row among good ones', function () {
        $payload = guestPayload();
        $payload['todos'][1]['title'] = '';

        $this->postJson('/api/v1/import', $payload)->assertUnprocessable()->assertJsonValidationErrors('todos.1.title');

        expect(nothingWritten())->toBeTrue();
    });

    it('validates every kind of row', function (string $key, array $change, string $errorKey) {
        $payload = guestPayload();
        data_set($payload, $key, $change[0]);

        $this->postJson('/api/v1/import', $payload)->assertUnprocessable()->assertJsonValidationErrors($errorKey);
        expect(nothingWritten())->toBeTrue();
    })->with([
        'todo id not a uuid' => ['todos.0.id', ['nope'], 'todos.0.id'],
        'todo category' => ['todos.0.category', ['holiday'], 'todos.0.category'],
        'todo date' => ['todos.0.due_date', ['26/10/2026'], 'todos.0.due_date'],
        'item times' => ['schedule_items.0.end_time', ['07:00'], 'schedule_items.0.end_time'],
        'item days empty' => ['schedule_items.0.days', [[]], 'schedule_items.0.days'],
        'item day range' => ['schedule_items.0.days', [[7]], 'schedule_items.0.days.0'],
        'item end date' => ['schedule_items.0.ends_on', ['2026-09-01'], 'schedule_items.0.ends_on'],
        'exception type' => ['exceptions.0.type', ['hide'], 'exceptions.0.type'],
        'exception date' => ['exceptions.0.date', ['2026-02-30'], 'exceptions.0.date'],
        'checkin date' => ['checkins.0.date', ['yesterday'], 'checkins.0.date'],
    ]);

    it('rejects an override that changes nothing', function () {
        $payload = guestPayload();
        $payload['exceptions'][1] = ['id' => (string) Str::uuid(), 'schedule_item_id' => $payload['schedule_items'][0]['id'], 'date' => '2026-10-26', 'type' => 'override'];

        $this->postJson('/api/v1/import', $payload)->assertUnprocessable()->assertJsonValidationErrors('exceptions.1.type');
        expect(nothingWritten())->toBeTrue();
    });

    it('rejects exceptions and check-ins that point at an unknown item', function () {
        $payload = guestPayload();
        $payload['checkins'][0]['schedule_item_id'] = (string) Str::uuid();

        $this->postJson('/api/v1/import', $payload)->assertUnprocessable()->assertJsonValidationErrors('checkins.0.schedule_item_id');
        expect(nothingWritten())->toBeTrue();
    });

    it('rejects duplicate ids and duplicate days inside the payload', function () {
        $payload = guestPayload();
        $payload['todos'][1]['id'] = $payload['todos'][0]['id'];
        $this->postJson('/api/v1/import', $payload)->assertUnprocessable()->assertJsonValidationErrors('todos.1.id');

        $payload = guestPayload();
        $payload['exceptions'][1]['date'] = $payload['exceptions'][0]['date'];
        $this->postJson('/api/v1/import', $payload)->assertUnprocessable()->assertJsonValidationErrors('exceptions.1.date');

        expect(nothingWritten())->toBeTrue();
    });

    it('rolls everything back if a write fails part-way', function () {
        ScheduleItem::creating(function () {
            throw new RuntimeException('disk full');
        });

        $this->postJson('/api/v1/import', guestPayload())->assertStatus(500);

        expect(nothingWritten())->toBeTrue();
    });

    it('requires a token and a json body shape', function () {
        $this->postJson('/api/v1/import', ['todos' => 'nope'])->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/import', [])->assertUnauthorized();
    });
});

describe('limits', function () {
    it('refuses more than 500 to-dos in total, counting existing ones', function () {
        Todo::factory()->for($this->user)->count(499)->create();

        $payload = ['todos' => [
            ['id' => (string) Str::uuid(), 'title' => 'a'],
            ['id' => (string) Str::uuid(), 'title' => 'b'],
        ]];

        $this->postJson('/api/v1/import', $payload)->assertUnprocessable()->assertJsonValidationErrors('todos');
        expect(Todo::count())->toBe(499);

        $this->postJson('/api/v1/import', ['todos' => [$payload['todos'][0]]])->assertOk();
        expect(Todo::count())->toBe(500);
    });

    it('refuses more than 100 schedule items in total', function () {
        ScheduleItem::factory()->for($this->user)->count(100)->create();

        $this->postJson('/api/v1/import', guestPayload(['todos' => [], 'exceptions' => [], 'checkins' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors('schedule_items');
        expect(ScheduleItem::count())->toBe(100);
    });

    it('refuses a payload over the size cap', function () {
        $todos = array_map(fn () => ['id' => (string) Str::uuid(), 'title' => 'x'], range(1, 501));

        $this->postJson('/api/v1/import', ['todos' => $todos])->assertUnprocessable()->assertJsonValidationErrors('todos');
        expect(Todo::count())->toBe(0);
    });

    it('does not count rows the user already has as new', function () {
        $payload = ['todos' => [['id' => (string) Str::uuid(), 'title' => 'a']]];
        Todo::factory()->for($this->user)->count(499)->create();
        $this->postJson('/api/v1/import', $payload)->assertOk();

        $this->postJson('/api/v1/import', $payload)->assertOk()->assertJsonPath('data.todos.skipped', 1);
    });
});

describe('other users\' ids', function () {
    it('refuses ids that belong to another account and writes nothing', function () {
        $other = User::factory()->create();
        $theirTodo = Todo::factory()->for($other)->create(['title' => 'Theirs']);
        $theirItem = ScheduleItem::factory()->for($other)->create(['title' => 'Their class']);
        $theirException = $theirItem->exceptions()->create(['date' => '2026-10-26', 'type' => 'skip']);
        $theirCheckin = $theirItem->checkins()->create(['date' => '2026-10-26', 'done_at' => now()]);

        $payload = guestPayload();
        $payload['todos'][0]['id'] = $theirTodo->id;
        $payload['schedule_items'][] = ['id' => $theirItem->id, 'title' => 'Hijack', 'start_time' => '08:00', 'end_time' => '09:00', 'days' => [1]];
        $payload['exceptions'][] = ['id' => $theirException->id, 'schedule_item_id' => $payload['schedule_items'][0]['id'], 'date' => '2026-11-02', 'type' => 'skip'];
        $payload['checkins'][] = ['id' => $theirCheckin->id, 'schedule_item_id' => $payload['schedule_items'][0]['id'], 'date' => '2026-11-02'];

        $this->postJson('/api/v1/import', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['todos.0.id', 'schedule_items.1.id', 'exceptions.2.id', 'checkins.1.id']);

        expect(Todo::count())->toBe(1)->and(Todo::first()->title)->toBe('Theirs')
            ->and(ScheduleItem::count())->toBe(1)->and(ScheduleItem::first()->title)->toBe('Their class')
            ->and($this->user->todos()->count())->toBe(0);
    });

    it('refuses exceptions and check-ins aimed at another user\'s item', function () {
        $theirItem = ScheduleItem::factory()->for(User::factory()->create())->create();

        $this->postJson('/api/v1/import', [
            'checkins' => [['id' => (string) Str::uuid(), 'schedule_item_id' => $theirItem->id, 'date' => '2026-10-26']],
            'exceptions' => [['id' => (string) Str::uuid(), 'schedule_item_id' => $theirItem->id, 'date' => '2026-10-26', 'type' => 'skip']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['checkins.0.schedule_item_id', 'exceptions.0.schedule_item_id']);

        expect(ScheduleCheckin::count())->toBe(0)->and(ScheduleException::count())->toBe(0);
    });

    it('never accepts a user_id from the client', function () {
        $other = User::factory()->create();
        $payload = guestPayload();
        $payload['todos'][0]['user_id'] = $other->id;
        $payload['schedule_items'][0]['user_id'] = $other->id;

        $this->postJson('/api/v1/import', $payload)->assertOk();

        expect($other->todos()->count())->toBe(0)->and($other->scheduleItems()->count())->toBe(0)
            ->and($this->user->todos()->count())->toBe(2);
    });
});
