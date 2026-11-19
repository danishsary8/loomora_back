<?php

use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    // 10:00 in Phnom Penh (UTC+7) on 2026-10-24.
    Carbon::setTestNow('2026-10-24 03:00:00');
    $this->user = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
    $this->actingAs($this->user, 'sanctum');
});

describe('priority and notes', function () {
    it('defaults priority to normal and notes to null', function () {
        $this->postJson('/api/v1/todos', ['title' => 'Read'])
            ->assertCreated()
            ->assertJsonPath('data.priority', 'normal')
            ->assertJsonPath('data.notes', null)
            ->assertJsonPath('data.parent_id', null)
            ->assertJsonPath('data.subtasks', []);
    });

    it('creates and edits priority and notes', function () {
        $id = $this->postJson('/api/v1/todos', ['title' => 'Essay', 'priority' => 'high', 'notes' => 'Chapter 2 sources'])
            ->assertCreated()
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.notes', 'Chapter 2 sources')
            ->json('data.id');

        $this->patchJson("/api/v1/todos/{$id}", ['priority' => 'low', 'notes' => null])
            ->assertOk()
            ->assertJsonPath('data.priority', 'low')
            ->assertJsonPath('data.notes', null);
    });

    it('validates priority and notes', function () {
        $this->postJson('/api/v1/todos', ['title' => 'x', 'priority' => 'urgent'])->assertUnprocessable()->assertJsonValidationErrors('priority');
        $this->postJson('/api/v1/todos', ['title' => 'x', 'priority' => null])->assertUnprocessable()->assertJsonValidationErrors('priority');
        $this->postJson('/api/v1/todos', ['title' => 'x', 'notes' => str_repeat('a', Todo::MAX_NOTES_LENGTH + 1)])
            ->assertUnprocessable()->assertJsonValidationErrors('notes');
        $this->postJson('/api/v1/todos', ['title' => 'x', 'notes' => str_repeat('a', Todo::MAX_NOTES_LENGTH)])->assertCreated();
    });
});

describe('creating subtasks', function () {
    it('creates a subtask that takes the parent category', function () {
        $parent = Todo::factory()->for($this->user)->due('2026-10-24')->create(['category' => 'study']);

        $this->postJson('/api/v1/todos', ['title' => 'Outline', 'parent_id' => $parent->id])
            ->assertCreated()
            ->assertJsonPath('data.parent_id', $parent->id)
            ->assertJsonPath('data.category', 'study')
            ->assertJsonPath('data.due_date', null);
    });

    it('refuses a subtask of a subtask', function () {
        $parent = Todo::factory()->for($this->user)->create();
        $child = Todo::factory()->subtaskOf($parent)->create();

        $this->postJson('/api/v1/todos', ['title' => 'Too deep', 'parent_id' => $child->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        expect(Todo::count())->toBe(2);
    });

    it('returns 404 for another user\'s parent_id', function () {
        $theirs = Todo::factory()->create();

        $this->postJson('/api/v1/todos', ['title' => 'Sneaky', 'parent_id' => $theirs->id])->assertNotFound();
        $this->postJson('/api/v1/todos', ['title' => 'Unknown', 'parent_id' => (string) Str::uuid()])->assertNotFound();

        expect($theirs->subtasks()->count())->toBe(0);
    });

    it('validates the parent_id format', function () {
        $this->postJson('/api/v1/todos', ['title' => 'x', 'parent_id' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    });

    it('refuses a due date on a subtask', function () {
        $parent = Todo::factory()->for($this->user)->create();
        $child = Todo::factory()->subtaskOf($parent)->create();

        $this->postJson('/api/v1/todos', ['title' => 'x', 'parent_id' => $parent->id, 'due_date' => '2026-10-24'])
            ->assertUnprocessable()->assertJsonValidationErrors('due_date');
        $this->patchJson("/api/v1/todos/{$child->id}", ['due_date' => '2026-10-24'])
            ->assertUnprocessable()->assertJsonValidationErrors('due_date');
    });

    it('counts subtasks toward the 500 to-dos limit', function () {
        $parent = Todo::factory()->for($this->user)->create();
        Todo::factory()->count(Todo::MAX_PER_USER - 1)->subtaskOf($parent)->create();

        $this->postJson('/api/v1/todos', ['title' => 'One too many', 'parent_id' => $parent->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    });
});

describe('listing', function () {
    it('lists top-level to-dos with subtasks nested in creation order', function () {
        $parent = Todo::factory()->for($this->user)->due('2026-10-24')->create();
        $second = Todo::factory()->subtaskOf($parent)->create(['created_at' => '2026-10-24 02:00:00']);
        $first = Todo::factory()->subtaskOf($parent)->create(['created_at' => '2026-10-24 01:00:00']);

        $data = $this->getJson('/api/v1/todos?date=2026-10-24')->assertOk()->json('data');

        expect($data)->toHaveCount(1)
            ->and($data[0]['id'])->toBe($parent->id)
            ->and(collect($data[0]['subtasks'])->pluck('id')->all())->toBe([$first->id, $second->id])
            ->and($data[0]['subtasks'][0]['parent_id'])->toBe($parent->id)
            ->and($data[0]['subtasks'][0]['subtasks'])->toBe([]);
    });

    it('keeps done subtasks inside an open parent', function () {
        $parent = Todo::factory()->for($this->user)->create();
        $done = Todo::factory()->subtaskOf($parent)->done()->create();

        $data = $this->getJson('/api/v1/todos?date=2026-10-24')->json('data');

        expect(collect($data[0]['subtasks'])->pluck('id')->all())->toBe([$done->id]);
    });

    it('returns subtasks on single responses', function () {
        $parent = Todo::factory()->for($this->user)->create();
        $child = Todo::factory()->subtaskOf($parent)->create();

        $this->patchJson("/api/v1/todos/{$parent->id}", ['title' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.subtasks.0.id', $child->id);
    });

    it('deletes subtasks with their parent', function () {
        $parent = Todo::factory()->for($this->user)->create();
        $child = Todo::factory()->subtaskOf($parent)->create();

        $this->deleteJson("/api/v1/todos/{$parent->id}")->assertNoContent();

        $this->assertSoftDeleted($child);
    });

    it('deletes subtasks with the account', function () {
        $parent = Todo::factory()->for($this->user)->create();
        Todo::factory()->subtaskOf($parent)->create();

        $this->user->delete();

        expect(Todo::count())->toBe(0);
    });
});

describe('checking off', function () {
    it('checks a parent\'s open subtasks with it and keeps earlier completion times', function () {
        $parent = Todo::factory()->for($this->user)->create();
        $open = Todo::factory()->subtaskOf($parent)->create();
        $earlier = Todo::factory()->subtaskOf($parent)->create(['done_at' => '2026-10-24 01:00:00']);

        $this->postJson("/api/v1/todos/{$parent->id}/done")
            ->assertOk()
            ->assertJsonPath('data.done_at', '2026-10-24T03:00:00Z')
            ->assertJsonPath('data.subtasks.0.done_at', '2026-10-24T03:00:00Z');

        expect($open->refresh()->done_at->toIso8601ZuluString())->toBe('2026-10-24T03:00:00Z')
            ->and($earlier->refresh()->done_at->toIso8601ZuluString())->toBe('2026-10-24T01:00:00Z');
    });

    it('leaves subtasks done when the parent is unchecked', function () {
        $parent = Todo::factory()->for($this->user)->create();
        $child = Todo::factory()->subtaskOf($parent)->create();
        $this->postJson("/api/v1/todos/{$parent->id}/done");

        $this->deleteJson("/api/v1/todos/{$parent->id}/done")->assertOk()->assertJsonPath('data.done_at', null);

        expect($child->refresh()->done_at)->not->toBeNull();
    });

    it('checks a subtask without touching its parent', function () {
        $parent = Todo::factory()->for($this->user)->create();
        $child = Todo::factory()->subtaskOf($parent)->create();

        $this->postJson("/api/v1/todos/{$child->id}/done")->assertOk()->assertJsonPath('data.subtasks', []);

        expect($parent->refresh()->done_at)->toBeNull();
    });

    it('counts a subtask check-off for the streak', function () {
        $parent = Todo::factory()->for($this->user)->create();
        $child = Todo::factory()->subtaskOf($parent)->create();

        $this->postJson("/api/v1/todos/{$child->id}/done")->assertOk();

        $this->getJson('/api/v1/streak?date=2026-10-24')
            ->assertOk()
            ->assertJsonPath('data.current_streak', 1)
            ->assertJsonPath('data.today_counted', true);
    });

    it('forbids checking another user\'s subtask', function () {
        $parent = Todo::factory()->create();
        $child = Todo::factory()->subtaskOf($parent)->create();

        $this->postJson("/api/v1/todos/{$child->id}/done")->assertForbidden();
    });
});
