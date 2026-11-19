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

function repeating(User $user, array $rule, string $due = '2026-10-24', array $attributes = []): Todo
{
    return Todo::factory()->for($user)->due($due)->create(['repeat_rule' => $rule, ...$attributes]);
}

function checkOffAndGetNext($test, Todo $todo): Todo
{
    $nextId = $test->postJson("/api/v1/todos/{$todo->id}/done")->assertOk()->json('data.next_todo_id');

    expect($nextId)->not->toBeNull();

    return Todo::findOrFail($nextId);
}

describe('creating the next to-do', function () {
    it('repeats daily on the next day with the same fields', function () {
        $todo = repeating($this->user, ['type' => 'daily'], attributes: ['title' => 'Read', 'category' => 'study', 'priority' => 'high', 'notes' => 'Ch. 4']);

        $next = checkOffAndGetNext($this, $todo);

        expect($next->due_date->toDateString())->toBe('2026-10-25')
            ->and($next->only(['title', 'category', 'priority', 'notes', 'repeat_rule', 'user_id']))
            ->toBe(['title' => 'Read', 'category' => 'study', 'priority' => 'high', 'notes' => 'Ch. 4', 'repeat_rule' => ['type' => 'daily'], 'user_id' => $this->user->id])
            ->and($next->done_at)->toBeNull()
            ->and($next->next_todo_id)->toBeNull();
    });

    it('repeats weekly on the next listed weekday, across the week boundary', function () {
        // Monday = 0, Wednesday = 2. Saturday's next match is Monday 2026-10-26.
        $next = checkOffAndGetNext($this, repeating($this->user, ['type' => 'weekly', 'days' => [2, 0]]));

        expect($next->due_date->toDateString())->toBe('2026-10-26')
            ->and($next->repeat_rule)->toBe(['type' => 'weekly', 'days' => [0, 2]]);
    });

    it('repeats weekly on the same weekday one week later', function () {
        $next = checkOffAndGetNext($this, repeating($this->user, ['type' => 'weekly', 'days' => [5]]));

        expect($next->due_date->toDateString())->toBe('2026-10-31');
    });

    it('uses the user\'s local today: at 00:30 in Phnom Penh the UTC date is still the day before', function () {
        Carbon::setTestNow('2026-10-24 17:30:00'); // 2026-10-25 00:30 local

        $next = checkOffAndGetNext($this, repeating($this->user, ['type' => 'daily']));

        expect($next->due_date->toDateString())->toBe('2026-10-26');
    });

    it('starts from today when an overdue repeating to-do is checked off', function () {
        $next = checkOffAndGetNext($this, repeating($this->user, ['type' => 'daily'], '2026-10-20'));

        expect($next->due_date->toDateString())->toBe('2026-10-25');
    });

    it('starts from the due date when a future one is checked off early', function () {
        $next = checkOffAndGetNext($this, repeating($this->user, ['type' => 'daily'], '2026-10-28'));

        expect($next->due_date->toDateString())->toBe('2026-10-29');
    });

    it('copies the subtasks undone, in order', function () {
        $todo = repeating($this->user, ['type' => 'daily']);
        Todo::factory()->subtaskOf($todo)->create(['title' => 'First', 'created_at' => '2026-10-24 01:00:00']);
        Todo::factory()->subtaskOf($todo)->done()->create(['title' => 'Second', 'created_at' => '2026-10-24 02:00:00']);

        $next = checkOffAndGetNext($this, $todo);

        expect($next->subtasks->pluck('title')->all())->toBe(['First', 'Second'])
            ->and($next->subtasks->pluck('done_at')->filter()->all())->toBe([])
            ->and($todo->subtasks()->whereNull('done_at')->count())->toBe(0);
    });

    it('creates only one next to-do when checked off, unchecked and checked off again', function () {
        $todo = repeating($this->user, ['type' => 'daily']);

        $first = $this->postJson("/api/v1/todos/{$todo->id}/done")->json('data.next_todo_id');
        $this->deleteJson("/api/v1/todos/{$todo->id}/done")->assertOk()->assertJsonPath('data.next_todo_id', $first);
        $this->postJson("/api/v1/todos/{$todo->id}/done")->assertOk()->assertJsonPath('data.next_todo_id', $first);

        expect(Todo::count())->toBe(2);
    });

    it('does nothing extra for a to-do that does not repeat', function () {
        $todo = Todo::factory()->for($this->user)->due('2026-10-24')->create();

        $this->postJson("/api/v1/todos/{$todo->id}/done")->assertOk()->assertJsonPath('data.next_todo_id', null);

        expect(Todo::count())->toBe(1);
    });
});

describe('validation', function () {
    it('creates a repeating to-do and returns the rule', function () {
        $this->postJson('/api/v1/todos', ['title' => 'Gym', 'due_date' => '2026-10-24', 'repeat_rule' => ['type' => 'weekly', 'days' => [4, 0, 4]]])
            ->assertUnprocessable()->assertJsonValidationErrors('repeat_rule.days.0');

        $this->postJson('/api/v1/todos', ['title' => 'Gym', 'due_date' => '2026-10-24', 'repeat_rule' => ['type' => 'weekly', 'days' => [4, 0]]])
            ->assertCreated()
            ->assertJsonPath('data.repeat_rule', ['type' => 'weekly', 'days' => [0, 4]])
            ->assertJsonPath('data.next_todo_id', null);

        $this->postJson('/api/v1/todos', ['title' => 'Read', 'due_date' => '2026-10-24', 'repeat_rule' => ['type' => 'daily', 'days' => [1]]])
            ->assertCreated()
            ->assertJsonPath('data.repeat_rule', ['type' => 'daily']);
    });

    it('requires a due date for a repeating to-do', function () {
        $this->postJson('/api/v1/todos', ['title' => 'Gym', 'repeat_rule' => ['type' => 'daily']])
            ->assertUnprocessable()->assertJsonValidationErrors('due_date');

        $todo = repeating($this->user, ['type' => 'daily']);
        $this->patchJson("/api/v1/todos/{$todo->id}", ['due_date' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('due_date');

        $undated = Todo::factory()->for($this->user)->create();
        $this->patchJson("/api/v1/todos/{$undated->id}", ['repeat_rule' => ['type' => 'daily']])
            ->assertUnprocessable()->assertJsonValidationErrors('due_date');
    });

    it('lets an edit add or remove the rule', function () {
        $todo = Todo::factory()->for($this->user)->due('2026-10-24')->create();

        $this->patchJson("/api/v1/todos/{$todo->id}", ['repeat_rule' => ['type' => 'daily']])
            ->assertOk()->assertJsonPath('data.repeat_rule', ['type' => 'daily']);
        $this->patchJson("/api/v1/todos/{$todo->id}", ['repeat_rule' => null, 'due_date' => null])
            ->assertOk()->assertJsonPath('data.repeat_rule', null);
    });

    it('rejects a bad rule', function (array $rule, string $errorKey) {
        $this->postJson('/api/v1/todos', ['title' => 'x', 'due_date' => '2026-10-24', 'repeat_rule' => $rule])
            ->assertUnprocessable()->assertJsonValidationErrors($errorKey);
    })->with([
        'unknown type' => [['type' => 'monthly'], 'repeat_rule.type'],
        'missing type' => [['days' => [1]], 'repeat_rule.type'],
        'weekly without days' => [['type' => 'weekly'], 'repeat_rule.days'],
        'weekly with no days' => [['type' => 'weekly', 'days' => []], 'repeat_rule.days'],
        'day out of range' => [['type' => 'weekly', 'days' => [7]], 'repeat_rule.days.0'],
        'extra key' => [['type' => 'daily', 'every' => 2], 'repeat_rule'],
    ]);

    it('refuses a repeat rule on a subtask', function () {
        $parent = Todo::factory()->for($this->user)->create();
        $child = Todo::factory()->subtaskOf($parent)->create();

        $this->postJson('/api/v1/todos', ['title' => 'x', 'parent_id' => $parent->id, 'repeat_rule' => ['type' => 'daily']])
            ->assertUnprocessable()->assertJsonValidationErrors('repeat_rule');
        $this->patchJson("/api/v1/todos/{$child->id}", ['repeat_rule' => ['type' => 'daily']])
            ->assertUnprocessable()->assertJsonValidationErrors('repeat_rule');
    });
});

describe('import', function () {
    it('imports a repeating to-do linked to its next one, in any order', function () {
        [$old, $next] = [(string) Str::uuid(), (string) Str::uuid()];

        $this->postJson('/api/v1/import', ['todos' => [
            ['id' => $old, 'title' => 'Gym', 'due_date' => '2026-10-23', 'done_at' => '2026-10-23T10:00:00Z', 'repeat_rule' => ['type' => 'daily'], 'next_todo_id' => $next],
            ['id' => $next, 'title' => 'Gym', 'due_date' => '2026-10-24', 'repeat_rule' => ['type' => 'daily']],
        ]])->assertOk()->assertJsonPath('data.todos.created', 2);

        expect(Todo::find($old)->next_todo_id)->toBe($next)
            ->and(Todo::find($next)->repeat_rule)->toBe(['type' => 'daily']);

        // Already linked, so checking it off again creates nothing.
        $this->postJson("/api/v1/todos/{$old}/done")->assertOk();
        expect(Todo::count())->toBe(2);
    });

    it('rejects a bad repeating to-do and writes nothing', function (Closure $row, string $errorKey) {
        $parent = (string) Str::uuid();

        $this->postJson('/api/v1/import', ['todos' => [
            ['id' => $parent, 'title' => 'Parent'],
            ['id' => (string) Str::uuid(), 'title' => 'x', ...$row($parent)],
        ]])->assertUnprocessable()->assertJsonValidationErrors($errorKey);

        expect(Todo::count())->toBe(0);
    })->with([
        'no due date' => [fn () => ['repeat_rule' => ['type' => 'daily']], 'todos.1.due_date'],
        'bad rule' => [fn () => ['due_date' => '2026-10-24', 'repeat_rule' => ['type' => 'weekly']], 'todos.1.repeat_rule.days'],
        'subtask' => [fn (string $parent) => ['parent_id' => $parent, 'repeat_rule' => ['type' => 'daily']], 'todos.1.repeat_rule'],
        'unknown next' => [fn () => ['next_todo_id' => (string) Str::uuid()], 'todos.1.next_todo_id'],
    ]);
});
