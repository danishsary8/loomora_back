<?php

use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 03:00:00');
    $this->user = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
    $this->actingAs($this->user, 'sanctum');
});

describe('deleting', function () {
    it('hides the to-do and its subtasks from the Today list', function () {
        $todo = Todo::factory()->for($this->user)->create();
        Todo::factory()->subtaskOf($todo)->create();
        $kept = Todo::factory()->for($this->user)->create();

        $this->deleteJson("/api/v1/todos/{$todo->id}")->assertNoContent();

        $this->getJson('/api/v1/todos?date=2026-10-24')->assertOk()->assertJsonPath('data.*.id', [$kept->id]);
        $this->assertSoftDeleted($todo);
        expect(Todo::onlyTrashed()->count())->toBe(2);
    });

    it('answers 404 when the to-do is already deleted', function () {
        $todo = Todo::factory()->for($this->user)->create();
        $this->deleteJson("/api/v1/todos/{$todo->id}")->assertNoContent();

        $this->deleteJson("/api/v1/todos/{$todo->id}")->assertNotFound();
        $this->patchJson("/api/v1/todos/{$todo->id}", ['title' => 'x'])->assertNotFound();
    });

    it('does not count deleted to-dos against the limit', function () {
        Todo::factory()->for($this->user)->count(Todo::MAX_PER_USER - 1)->create();
        $todo = Todo::factory()->for($this->user)->create();
        $todo->deleteWithSubtasks();

        $this->postJson('/api/v1/todos', ['title' => 'Fits now'])->assertCreated();
    });

    it('lets a deleted next to-do of a repeat stop being referenced', function () {
        $next = Todo::factory()->for($this->user)->create();
        $first = Todo::factory()->for($this->user)->create(['next_todo_id' => $next->id]);

        $this->deleteJson("/api/v1/todos/{$next->id}")->assertNoContent();

        expect($first->fresh()->next_todo_id)->toBeNull();
    });
});

describe('restoring', function () {
    it('brings back the same to-do with its subtasks', function () {
        $todo = Todo::factory()->for($this->user)->create(['title' => 'Essay']);
        $child = Todo::factory()->subtaskOf($todo)->create(['title' => 'Outline']);
        $this->deleteJson("/api/v1/todos/{$todo->id}")->assertNoContent();

        $this->postJson("/api/v1/todos/{$todo->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $todo->id)
            ->assertJsonPath('data.title', 'Essay')
            ->assertJsonPath('data.subtasks.*.id', [$child->id]);

        $this->getJson('/api/v1/todos?date=2026-10-24')->assertOk()->assertJsonPath('data.0.id', $todo->id);
        $this->assertNotSoftDeleted($child);
    });

    it('does not bring back a subtask that was deleted on its own earlier', function () {
        $todo = Todo::factory()->for($this->user)->create();
        $early = Todo::factory()->subtaskOf($todo)->create();
        $early->deleteWithSubtasks();
        Carbon::setTestNow('2026-10-24 04:00:00');
        $this->deleteJson("/api/v1/todos/{$todo->id}")->assertNoContent();

        $this->postJson("/api/v1/todos/{$todo->id}/restore")->assertOk()->assertJsonCount(0, 'data.subtasks');

        $this->assertSoftDeleted($early);
    });

    it('works just under 7 days and returns 410 after 8 days', function () {
        $recent = Todo::factory()->for($this->user)->create();
        $old = Todo::factory()->for($this->user)->create();
        $this->deleteJson("/api/v1/todos/{$recent->id}")->assertNoContent();
        $this->deleteJson("/api/v1/todos/{$old->id}")->assertNoContent();

        Carbon::setTestNow(now()->addDays(7)->subMinute());
        $this->postJson("/api/v1/todos/{$recent->id}/restore")->assertOk();

        Carbon::setTestNow('2026-11-01 03:00:00');
        $this->postJson("/api/v1/todos/{$old->id}/restore")
            ->assertStatus(410)
            ->assertJsonStructure(['message']);
        $this->assertSoftDeleted($old);
    });

    it('returns 404 for another user\'s to-do and an unknown id', function () {
        $theirs = Todo::factory()->for(User::factory()->create())->create();
        $theirs->deleteWithSubtasks();

        $this->postJson("/api/v1/todos/{$theirs->id}/restore")->assertNotFound();
        $this->postJson('/api/v1/todos/'.fake()->uuid().'/restore')->assertNotFound();
        $this->assertSoftDeleted($theirs);
    });

    it('returns a to-do that is not deleted unchanged', function () {
        $todo = Todo::factory()->for($this->user)->create();

        $this->postJson("/api/v1/todos/{$todo->id}/restore")->assertOk()->assertJsonPath('data.id', $todo->id);
    });

    it('refuses to restore a subtask while its parent is deleted', function () {
        $parent = Todo::factory()->for($this->user)->create();
        $child = Todo::factory()->subtaskOf($parent)->create();
        $parent->deleteWithSubtasks();

        $this->postJson("/api/v1/todos/{$child->id}/restore")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    });

    it('needs a signed-in user', function () {
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/todos/'.fake()->uuid().'/restore')->assertUnauthorized();
    });
});

describe('reminders and import', function () {
    it('drops a deleted to-do from the due reminders', function () {
        $todo = Todo::factory()->for($this->user)->create(['remind_at' => '2026-10-24T01:00:00Z']);
        $this->deleteJson("/api/v1/todos/{$todo->id}")->assertNoContent();

        $this->getJson('/api/v1/reminders/due')->assertOk()->assertJsonCount(0, 'data');
    });

    it('skips a to-do the user already deleted instead of failing', function () {
        $todo = Todo::factory()->for($this->user)->create();
        $todo->deleteWithSubtasks();

        $this->postJson('/api/v1/import', ['todos' => [['id' => $todo->id, 'title' => 'Again']]])
            ->assertOk()
            ->assertJsonPath('data.todos.skipped', 1);
    });

    it('does not let an import reuse the id of another user\'s deleted to-do', function () {
        $theirs = Todo::factory()->for(User::factory()->create())->create();
        $theirs->deleteWithSubtasks();

        $this->postJson('/api/v1/import', ['todos' => [['id' => $theirs->id, 'title' => 'Mine']]])->assertUnprocessable();
    });
});

describe('account deletion', function () {
    it('removes every to-do of the user, including deleted ones', function () {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);
        $live = Todo::factory()->for($user)->create();
        Todo::factory()->subtaskOf($live)->create();
        $gone = Todo::factory()->for($user)->create();
        $gone->deleteWithSubtasks();
        $other = Todo::factory()->for(User::factory()->create())->create();

        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/me', ['password' => 'correct-horse-battery'])->assertNoContent();

        expect(Todo::withTrashed()->where('user_id', $user->id)->count())->toBe(0)
            ->and(Todo::withTrashed()->whereKey($other->id)->exists())->toBeTrue();
    });
});
