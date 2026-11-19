<?php

use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Saturday 2026-10-24, 10:00 in Phnom Penh (UTC+7).
    Carbon::setTestNow('2026-10-24 03:00:00');
    $this->user = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
    $this->actingAs($this->user, 'sanctum');
});

function withReminder(User $user, string $remindAt, array $attributes = []): Todo
{
    return Todo::factory()->for($user)->create(['remind_at' => $remindAt, ...$attributes]);
}

function dueReminderIds($test): array
{
    return $test->getJson('/api/v1/reminders/due')->assertOk()->json('data.*.id');
}

describe('setting a reminder', function () {
    it('stores remind_at in UTC whatever offset is sent', function () {
        $id = $this->postJson('/api/v1/todos', ['title' => 'Call mum', 'remind_at' => '2026-10-24T18:30:00+07:00'])
            ->assertCreated()
            ->assertJsonPath('data.remind_at', '2026-10-24T11:30:00Z')
            ->json('data.id');

        expect(Todo::findOrFail($id)->getRawOriginal('remind_at'))->toStartWith('2026-10-24 11:30:00');
    });

    it('accepts toISOString() output with milliseconds', function () {
        $this->postJson('/api/v1/todos', ['title' => 'Call mum', 'remind_at' => '2026-10-24T11:30:00.000Z'])
            ->assertCreated()
            ->assertJsonPath('data.remind_at', '2026-10-24T11:30:00Z');
    });

    it('rejects a date-time without an offset', function (string $value) {
        $this->postJson('/api/v1/todos', ['title' => 'Call mum', 'remind_at' => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('remind_at');
    })->with(['2026-10-24T11:30:00', '2026-10-24 11:30:00', 'tomorrow']);

    it('can be cleared with null', function () {
        $todo = withReminder($this->user, '2026-10-24 02:00:00');

        $this->patchJson("/api/v1/todos/{$todo->id}", ['remind_at' => null])
            ->assertOk()
            ->assertJsonPath('data.remind_at', null);
    });

    it('is not allowed on a subtask', function () {
        $parent = Todo::factory()->for($this->user)->create();
        $subtask = Todo::factory()->subtaskOf($parent)->create();

        $this->postJson('/api/v1/todos', ['title' => 'Step', 'parent_id' => $parent->id, 'remind_at' => '2026-10-24T11:30:00Z'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('remind_at');
        $this->patchJson("/api/v1/todos/{$subtask->id}", ['remind_at' => '2026-10-24T11:30:00Z'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('remind_at');
        $this->postJson("/api/v1/todos/{$subtask->id}/reminder/snooze", ['minutes' => 5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('remind_at');
    });

    it('brings a dismissed reminder back when remind_at changes', function () {
        $todo = withReminder($this->user, '2026-10-24 02:00:00', ['remind_dismissed_at' => '2026-10-24 02:05:00']);

        $this->patchJson("/api/v1/todos/{$todo->id}", ['remind_at' => '2026-10-24T02:30:00Z'])->assertOk();

        expect($todo->fresh()->remind_dismissed_at)->toBeNull()
            ->and(dueReminderIds($this))->toBe([$todo->id]);
    });

    it('keeps the dismissal when other fields change', function () {
        $todo = withReminder($this->user, '2026-10-24 02:00:00', ['remind_dismissed_at' => '2026-10-24 02:05:00']);

        $this->patchJson("/api/v1/todos/{$todo->id}", ['title' => 'Renamed', 'remind_at' => '2026-10-24T02:00:00Z'])->assertOk();

        expect($todo->fresh()->remind_dismissed_at)->not->toBeNull();
    });
});

describe('the due list', function () {
    it('lists reminders whose time has passed, oldest first', function () {
        $later = withReminder($this->user, '2026-10-24 02:59:00');
        $exactlyNow = withReminder($this->user, '2026-10-24 03:00:00');
        $earlier = withReminder($this->user, '2026-10-23 20:00:00');
        withReminder($this->user, '2026-10-24 03:01:00'); // not yet
        Todo::factory()->for($this->user)->create(); // no reminder

        $this->getJson('/api/v1/reminders/due')
            ->assertOk()
            ->assertJsonPath('data.0.remind_at', '2026-10-23T20:00:00Z')
            ->assertJsonPath('data.0.subtasks', []);
        expect(dueReminderIds($this))->toBe([$earlier->id, $later->id, $exactlyNow->id]);
    });

    it('leaves out done, dismissed and deleted to-dos', function () {
        withReminder($this->user, '2026-10-24 02:00:00', ['done_at' => '2026-10-24 02:30:00']);
        withReminder($this->user, '2026-10-24 02:00:00', ['remind_dismissed_at' => '2026-10-24 02:10:00']);
        withReminder($this->user, '2026-10-24 02:00:00')->delete();
        $open = withReminder($this->user, '2026-10-24 02:00:00');

        expect(dueReminderIds($this))->toBe([$open->id]);
    });

    it('shows only the user\'s own to-dos', function () {
        withReminder(User::factory()->create(), '2026-10-24 02:00:00');

        expect(dueReminderIds($this))->toBe([]);
    });

    it('returns at most 50', function () {
        foreach (range(1, 55) as $minute) {
            withReminder($this->user, Carbon::parse('2026-10-24 01:00:00')->addMinutes($minute)->toDateTimeString());
        }

        $data = $this->getJson('/api/v1/reminders/due')->assertOk()->json('data');

        expect($data)->toHaveCount(Todo::MAX_DUE_REMINDERS)
            ->and($data[0]['remind_at'])->toBe('2026-10-24T01:01:00Z');
    });
});

describe('dismiss', function () {
    it('hides the reminder from the due list and keeps remind_at', function () {
        $todo = withReminder($this->user, '2026-10-24 02:00:00');

        $this->postJson("/api/v1/todos/{$todo->id}/reminder/dismiss")
            ->assertOk()
            ->assertJsonPath('data.remind_at', '2026-10-24T02:00:00Z');

        expect($todo->fresh()->remind_dismissed_at->toDateTimeString())->toBe('2026-10-24 03:00:00')
            ->and(dueReminderIds($this))->toBe([]);
    });

    it('keeps the first dismissal time when called again', function () {
        $todo = withReminder($this->user, '2026-10-24 02:00:00');
        $this->postJson("/api/v1/todos/{$todo->id}/reminder/dismiss")->assertOk();

        Carbon::setTestNow('2026-10-24 03:10:00');
        $this->postJson("/api/v1/todos/{$todo->id}/reminder/dismiss")->assertOk();

        expect($todo->fresh()->remind_dismissed_at->toDateTimeString())->toBe('2026-10-24 03:00:00');
    });

    it('returns 404 for another user\'s to-do', function () {
        $todo = withReminder(User::factory()->create(), '2026-10-24 02:00:00');

        $this->postJson("/api/v1/todos/{$todo->id}/reminder/dismiss")->assertNotFound();

        expect($todo->fresh()->remind_dismissed_at)->toBeNull();
    });

    it('returns 404 for an unknown to-do', function () {
        $this->postJson('/api/v1/todos/'.Str::uuid().'/reminder/dismiss')->assertNotFound();
    });
});

describe('snooze', function () {
    it('moves the reminder to now plus the minutes and clears the dismissal', function (int $minutes) {
        $todo = withReminder($this->user, '2026-10-24 02:00:00', ['remind_dismissed_at' => '2026-10-24 02:05:00']);

        $this->postJson("/api/v1/todos/{$todo->id}/reminder/snooze", ['minutes' => $minutes])
            ->assertOk()
            ->assertJsonPath('data.remind_at', now()->addMinutes($minutes)->toIso8601ZuluString());

        expect($todo->fresh()->remind_dismissed_at)->toBeNull()
            ->and(dueReminderIds($this))->toBe([]);

        Carbon::setTestNow(now()->addMinutes($minutes));
        expect(dueReminderIds($this))->toBe([$todo->id]);
    })->with([5, 10, 30, 60]);

    it('rejects other values', function (mixed $minutes) {
        $todo = withReminder($this->user, '2026-10-24 02:00:00');

        $this->postJson("/api/v1/todos/{$todo->id}/reminder/snooze", ['minutes' => $minutes])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('minutes');

        expect($todo->fresh()->remind_at->toDateTimeString())->toBe('2026-10-24 02:00:00');
    })->with([0, 15, 120, -5, 'ten', null]);

    it('returns 404 for another user\'s to-do', function () {
        $todo = withReminder(User::factory()->create(), '2026-10-24 02:00:00');

        $this->postJson("/api/v1/todos/{$todo->id}/reminder/snooze", ['minutes' => 5])->assertNotFound();

        expect($todo->fresh()->remind_at->toDateTimeString())->toBe('2026-10-24 02:00:00');
    });
});

describe('repeats and import', function () {
    it('imports remind_at and remind_dismissed_at in UTC', function () {
        $due = (string) Str::uuid();
        $dismissed = (string) Str::uuid();

        $this->postJson('/api/v1/import', ['todos' => [
            ['id' => $due, 'title' => 'Due', 'remind_at' => '2026-10-24T09:00:00+07:00'],
            ['id' => $dismissed, 'title' => 'Dismissed', 'remind_at' => '2026-10-24T01:00:00Z', 'remind_dismissed_at' => '2026-10-24T08:30:00+07:00'],
        ]])->assertOk();

        expect(Todo::findOrFail($dismissed)->remind_dismissed_at->toDateTimeString())->toBe('2026-10-24 01:30:00')
            ->and(dueReminderIds($this))->toBe([$due]);
    });

    it('rejects a reminder on an imported subtask and a bad date-time', function () {
        $parent = (string) Str::uuid();

        $this->postJson('/api/v1/import', ['todos' => [
            ['id' => $parent, 'title' => 'Parent', 'remind_at' => 'soon'],
            ['id' => (string) Str::uuid(), 'title' => 'Step', 'parent_id' => $parent, 'remind_at' => '2026-10-24T01:00:00Z'],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['todos.0.remind_at']);

        $this->postJson('/api/v1/import', ['todos' => [
            ['id' => $parent, 'title' => 'Parent'],
            ['id' => (string) Str::uuid(), 'title' => 'Step', 'parent_id' => $parent, 'remind_at' => '2026-10-24T01:00:00Z'],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['todos.1.remind_at']);

        expect(Todo::count())->toBe(0);
    });
});
