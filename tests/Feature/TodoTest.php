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

function todayIds($test, string $date = '2026-10-24'): array
{
    return collect($test->getJson("/api/v1/todos?date={$date}")->assertOk()->json('data'))->pluck('id')->all();
}

describe('Today list rules', function () {
    it('shows to-dos due today, done or not', function () {
        $open = Todo::factory()->for($this->user)->due('2026-10-24')->create();
        $done = Todo::factory()->for($this->user)->due('2026-10-24')->done()->create();

        expect(todayIds($this))->toEqualCanonicalizing([$open->id, $done->id]);
    });

    it('shows undated to-dos only while they are not done', function () {
        $open = Todo::factory()->for($this->user)->create();
        Todo::factory()->for($this->user)->done()->create();

        expect(todayIds($this))->toBe([$open->id]);
    });

    it('shows unfinished past to-dos as overdue, first in the list', function () {
        $today = Todo::factory()->for($this->user)->due('2026-10-24')->create();
        $overdue = Todo::factory()->for($this->user)->due('2026-10-20')->create();
        Todo::factory()->for($this->user)->due('2026-10-20')->done()->create();

        $data = $this->getJson('/api/v1/todos?date=2026-10-24')->assertOk()->json('data');

        expect(collect($data)->pluck('id')->all())->toBe([$overdue->id, $today->id])
            ->and($data[0]['overdue'])->toBeTrue()
            ->and($data[0]['old'])->toBeFalse()
            ->and($data[1]['overdue'])->toBeFalse();
    });

    it('marks overdue items older than 30 days as old', function () {
        Todo::factory()->for($this->user)->due('2026-09-23')->create();
        Todo::factory()->for($this->user)->due('2026-09-24')->create();

        $data = collect($this->getJson('/api/v1/todos?date=2026-10-24')->json('data'))->keyBy('due_date');

        expect($data['2026-09-23']['old'])->toBeTrue()
            ->and($data['2026-09-24']['old'])->toBeFalse();
    });

    it('hides future-dated to-dos until their day', function () {
        $tomorrow = Todo::factory()->for($this->user)->due('2026-10-25')->create();

        expect(todayIds($this))->toBe([])
            ->and(todayIds($this, '2026-10-25'))->toBe([$tomorrow->id]);
    });

    it('uses the date the client sends, within a day of the user time zone', function () {
        $this->getJson('/api/v1/todos?date=2026-10-23')->assertOk();
        $this->getJson('/api/v1/todos?date=2026-10-25')->assertOk();
        $this->getJson('/api/v1/todos?date=2026-10-27')->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->getJson('/api/v1/todos?date=24-10-2026')->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->getJson('/api/v1/todos')->assertUnprocessable()->assertJsonValidationErrors('date');
    });
});

describe('create, edit, delete', function () {
    it('creates a to-do with optional fields and a server uuid', function () {
        $response = $this->postJson('/api/v1/todos', ['title' => '  Read chapter 3  '])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Read chapter 3')
            ->assertJsonPath('data.category', 'other')
            ->assertJsonPath('data.due_date', null)
            ->assertJsonPath('data.done_at', null)
            ->assertJsonPath('data.overdue', false);

        expect(Str::isUuid($response->json('data.id')))->toBeTrue();

        $this->postJson('/api/v1/todos', ['title' => 'Gym', 'category' => 'health', 'due_date' => '2026-10-24'])
            ->assertCreated()
            ->assertJsonPath('data.category', 'health')
            ->assertJsonPath('data.due_date', '2026-10-24');
    });

    it('validates title, category and due date', function () {
        $this->postJson('/api/v1/todos', ['title' => '   '])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->postJson('/api/v1/todos', ['title' => str_repeat('a', 201)])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->postJson('/api/v1/todos', ['title' => 'x', 'category' => 'faith'])->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->postJson('/api/v1/todos', ['title' => 'x', 'due_date' => 'tomorrow'])->assertUnprocessable()->assertJsonValidationErrors('due_date');
    });

    it('never takes user_id from the client', function () {
        $other = User::factory()->create();

        $id = $this->postJson('/api/v1/todos', ['title' => 'Mine', 'user_id' => $other->id])->assertCreated()->json('data.id');

        expect(Todo::find($id)->user_id)->toBe($this->user->id);
    });

    it('enforces the 500 to-dos per user limit', function () {
        Todo::factory()->count(Todo::MAX_PER_USER)->for($this->user)->create();

        $this->postJson('/api/v1/todos', ['title' => 'One too many'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    });

    it('edits title, category and due date', function () {
        $todo = Todo::factory()->for($this->user)->due('2026-10-24')->create(['category' => 'work']);

        $this->patchJson("/api/v1/todos/{$todo->id}", ['title' => 'New title', 'category' => 'study', 'due_date' => null])
            ->assertOk()
            ->assertJsonPath('data.title', 'New title')
            ->assertJsonPath('data.category', 'study')
            ->assertJsonPath('data.due_date', null);
    });

    it('deletes a to-do', function () {
        $todo = Todo::factory()->for($this->user)->create();

        $this->deleteJson("/api/v1/todos/{$todo->id}")->assertNoContent();

        $this->assertModelMissing($todo);
    });

    it('returns 404 for an unknown or malformed id', function () {
        $this->patchJson('/api/v1/todos/'.Str::uuid(), ['title' => 'x'])->assertNotFound();
        $this->patchJson('/api/v1/todos/not-a-uuid', ['title' => 'x'])->assertNotFound();
    });
});

describe('check-off', function () {
    it('sets and clears done_at', function () {
        $todo = Todo::factory()->for($this->user)->due('2026-10-24')->create();

        $this->postJson("/api/v1/todos/{$todo->id}/done")->assertOk()->assertJsonPath('data.done_at', '2026-10-24T03:00:00Z');
        $this->deleteJson("/api/v1/todos/{$todo->id}/done")->assertOk()->assertJsonPath('data.done_at', null);
    });

    it('keeps the first completion time when marked done twice', function () {
        $todo = Todo::factory()->for($this->user)->create();
        $this->postJson("/api/v1/todos/{$todo->id}/done");

        Carbon::setTestNow('2026-10-24 05:00:00');

        $this->postJson("/api/v1/todos/{$todo->id}/done")->assertJsonPath('data.done_at', '2026-10-24T03:00:00Z');
    });

    it('removes an overdue to-do from the list once it is done', function () {
        $todo = Todo::factory()->for($this->user)->due('2026-10-20')->create();
        $this->postJson("/api/v1/todos/{$todo->id}/done");

        expect(todayIds($this))->toBe([]);
    });
});

describe('ownership', function () {
    it('never lists another user\'s to-dos', function () {
        Todo::factory()->due('2026-10-24')->create();
        Todo::factory()->create();

        expect(todayIds($this))->toBe([]);
    });

    it('forbids reading or changing another user\'s to-do', function () {
        $theirs = Todo::factory()->due('2026-10-24')->create(['title' => 'Theirs']);

        $this->patchJson("/api/v1/todos/{$theirs->id}", ['title' => 'Hacked'])->assertForbidden();
        $this->postJson("/api/v1/todos/{$theirs->id}/done")->assertForbidden();
        $this->deleteJson("/api/v1/todos/{$theirs->id}/done")->assertForbidden();
        $this->deleteJson("/api/v1/todos/{$theirs->id}")->assertForbidden();

        $theirs->refresh();
        expect($theirs->title)->toBe('Theirs')->and($theirs->done_at)->toBeNull();
    });

    it('requires a token', function () {
        $this->app['auth']->forgetGuards();
        auth()->guard('sanctum')->forgetUser();

        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/todos?date=2026-10-24')->assertUnauthorized();
    });

    it('deletes a user\'s to-dos with the account', function () {
        $todo = Todo::factory()->for($this->user)->create();

        $this->user->delete();

        $this->assertModelMissing($todo);
    });
});
