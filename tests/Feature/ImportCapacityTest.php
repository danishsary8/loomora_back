<?php

use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
 * B-044: the 500-to-do limit counts manual rows only. A repeat successor (a to-do another
 * to-do of the same user points at with `next_todo_id`) and its subtasks are exempt, as in I5.
 * The 500-row request cap stays; each request is all-or-nothing.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-26 03:00:00');
    $this->user = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
    $this->actingAs($this->user, 'sanctum');
});

/** @return array<string, mixed> */
function manualRow(array $fields = []): array
{
    return ['id' => (string) Str::uuid(), 'title' => 'Manual', ...$fields];
}

/** @return list<array<string, mixed>> */
function manualRows(int $count): array
{
    return array_map(fn () => manualRow(), $count > 0 ? range(1, $count) : []);
}

/**
 * A guest plan: `$manual` manual rows, the first of them a daily repeat that was checked off
 * `$generated` times, so it heads a chain of `$generated` successors, each with `$subtasks` copied subtasks.
 *
 * The chain follows the B-044 ordering contract: newest successor first, down to the original, and
 * each successor's subtasks right after that successor's predecessor. Every reference then points at
 * a row sent earlier or in the same chunk, and at most one successor per chain (without subtasks)
 * waits for its predecessor in a later chunk.
 *
 * @return list<array<string, mixed>>
 */
function guestPlan(int $manual, int $generated, int $subtasks = 0, bool $manualFirst = false): array
{
    $ids = array_map(fn () => (string) Str::uuid(), range(0, $generated));
    $chain = [];
    for ($i = $generated; $i >= 0; $i--) {
        $chain[] = [
            'id' => $ids[$i],
            'title' => 'Stretch',
            'due_date' => Carbon::parse('2026-01-01')->addDays($i)->toDateString(),
            'done_at' => $i < $generated ? '2026-10-01T00:00:00Z' : null,
            'repeat_rule' => ['type' => 'daily'],
            'next_todo_id' => $ids[$i + 1] ?? null,
            'priority' => 'high',
            'notes' => "Day {$i}",
        ];
        for ($s = 1; $i < $generated && $s <= $subtasks; $s++) {
            $chain[] = ['id' => (string) Str::uuid(), 'title' => "Step {$s}", 'parent_id' => $ids[$i + 1]];
        }
    }

    return $manualFirst ? [...manualRows($manual - 1), ...$chain] : [...$chain, ...manualRows($manual - 1)];
}

/**
 * The request the current frontend sends (`saveGuestPlan.ts`, `toImportBody` and `chunkImportBody`):
 * every to-do in the first call, in IndexedDB key (id) order, top-level to-dos before subtasks.
 *
 * @return array{todos: list<array<string, mixed>>}
 */
function frontendRequest(array $plan): array
{
    usort($plan, fn (array $a, array $b) => strcmp($a['id'], $b['id']));
    usort($plan, fn (array $a, array $b) => empty($b['parent_id']) <=> empty($a['parent_id']));

    return ['todos' => $plan];
}

/** No to-do points at a missing next to-do or parent. */
function brokenReferences(): int
{
    return Todo::withTrashed()->whereNotNull('next_todo_id')->whereNotIn('next_todo_id', Todo::withTrashed()->select('id'))->count()
        + Todo::withTrashed()->whereNotNull('parent_id')->whereNotIn('parent_id', Todo::withTrashed()->select('id'))->count();
}

/** Sends the plan in chunks of at most 500 rows, the request cap. */
function importInChunks(array $plan): void
{
    foreach (array_chunk($plan, Todo::MAX_PER_USER) as $chunk) {
        test()->postJson('/api/v1/import', ['todos' => $chunk])->assertOk();
    }
}

/** A successor already in the account: `$predecessor` was checked off and points at it. */
function accountSuccessor(User $user, int $subtasks = 0): Todo
{
    $next = Todo::factory()->for($user)->create(['due_date' => '2026-10-27', 'repeat_rule' => ['type' => 'daily']]);
    Todo::factory()->for($user)->count($subtasks)->create(['parent_id' => $next->id]);
    Todo::factory()->for($user)->create(['due_date' => '2026-10-26', 'repeat_rule' => ['type' => 'daily'], 'done_at' => now(), 'next_todo_id' => $next->id]);

    return $next;
}

describe('request size', function () {
    it('accepts up to 500 manual rows in one request and refuses 501 without writing', function (int $rows, bool $ok) {
        $todos = manualRows($rows);

        $response = $this->postJson('/api/v1/import', ['todos' => $todos]);

        if ($ok) {
            $response->assertOk()->assertJsonPath('data.todos.created', $rows);
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('todos');
        }
        expect(Todo::count())->toBe($ok ? $rows : 0);
    })->with([
        '499' => [499, true],
        '500' => [500, true],
        '501' => [501, false],
    ]);
});

describe('repeat-generated rows', function () {
    it('imports a full plan of 500 manual rows plus repeat-generated rows, losslessly', function (int $generated) {
        $plan = guestPlan(500, $generated, 1);

        importInChunks($plan);

        expect($this->user->todos()->count())->toBe(500 + 2 * $generated);
        foreach ($plan as $row) {
            $todo = Todo::find($row['id']);
            expect($todo->user_id)->toBe($this->user->id)
                ->and($todo->title)->toBe($row['title'])
                ->and($todo->parent_id)->toBe($row['parent_id'] ?? null)
                ->and($todo->next_todo_id)->toBe($row['next_todo_id'] ?? null)
                ->and($todo->repeat_rule)->toBe($row['repeat_rule'] ?? null)
                ->and($todo->due_date?->toDateString())->toBe($row['due_date'] ?? null)
                ->and($todo->notes)->toBe($row['notes'] ?? null);
        }
    })->with([
        '1 generated' => [1],
        '499 generated' => [499],
        '500 generated' => [500],
        '501 generated' => [501],
        '600 generated' => [600],
    ]);

    it('exempts a successor and its subtasks when sent with its predecessor', function () {
        Todo::factory()->for($this->user)->count(498)->create();

        // 2 manual (the checked-off original and one more) + 1 successor + 2 subtasks = 3 exempt.
        $plan = guestPlan(2, 1, 2);
        $this->postJson('/api/v1/import', ['todos' => $plan])->assertOk()->assertJsonPath('data.todos.created', 5);

        expect($this->user->todos()->count())->toBe(503);
    });

    it('still counts the 501st manual row when generated rows are exempt', function () {
        Todo::factory()->for($this->user)->count(499)->create();

        $this->postJson('/api/v1/import', ['todos' => guestPlan(2, 1, 2)])
            ->assertUnprocessable()->assertJsonValidationErrors('todos');
        expect($this->user->todos()->count())->toBe(499);
    });

    it('counts a manual to-do with a repeat rule that nothing points at', function () {
        Todo::factory()->for($this->user)->count(500)->create();

        $this->postJson('/api/v1/import', ['todos' => [
            manualRow(['due_date' => '2026-10-27', 'repeat_rule' => ['type' => 'daily']]),
        ]])->assertUnprocessable()->assertJsonValidationErrors('todos');
        expect($this->user->todos()->count())->toBe(500);
    });

    it('counts a successor sent without its predecessor as manual until the predecessor arrives', function () {
        Todo::factory()->for($this->user)->count(500)->create();
        [$successor] = guestPlan(1, 1);

        $this->postJson('/api/v1/import', ['todos' => [$successor]])
            ->assertUnprocessable()->assertJsonValidationErrors('todos');
        expect(Todo::find($successor['id']))->toBeNull();
    });

    it('does not take provenance from a payload row the account already has', function () {
        $existing = Todo::factory()->for($this->user)->create(['due_date' => '2026-10-26', 'repeat_rule' => ['type' => 'daily']]);
        Todo::factory()->for($this->user)->count(499)->create();
        $claimed = manualRow();

        // The existing row is skipped on import, so its claimed link proves nothing.
        $this->postJson('/api/v1/import', ['todos' => [
            $claimed,
            ['id' => $existing->id, 'title' => 'Stretch', 'due_date' => '2026-10-26', 'repeat_rule' => ['type' => 'daily'], 'next_todo_id' => $claimed['id']],
        ]])->assertUnprocessable()->assertJsonValidationErrors('todos');
        expect(Todo::find($claimed['id']))->toBeNull();
    });
});

describe('chunk order near capacity', function () {
    it('imports the contract order when manual rows come first and the account is almost full', function () {
        // 301 account + 199 guest manual = 500. The first chunk holds 198 manual rows and the newest
        // 100 successors, ending on a successor whose predecessor is in the next chunk.
        Todo::factory()->for($this->user)->count(301)->create();
        $plan = guestPlan(199, 600, 2, manualFirst: true);

        importInChunks($plan);

        expect($this->user->todos()->count())->toBe(301 + count($plan))
            ->and(brokenReferences())->toBe(0);
    });
});

describe('the current frontend request', function () {
    it('imports a plan that fits one request into an almost full account, and replays it', function () {
        Todo::factory()->for($this->user)->count(450)->create();
        $plan = guestPlan(50, 150, 2); // 50 manual + 450 generated = 500 rows, 500 manual in total

        $this->postJson('/api/v1/import', frontendRequest($plan))->assertOk()->assertJsonPath('data.todos.created', 500);
        $this->postJson('/api/v1/import', frontendRequest($plan))->assertOk()->assertJsonPath('data.todos', ['created' => 0, 'skipped' => 500]);

        expect($this->user->todos()->count())->toBe(950)->and(brokenReferences())->toBe(0);
        foreach ($plan as $row) {
            expect(Todo::find($row['id'])->next_todo_id)->toBe($row['next_todo_id'] ?? null)
                ->and(Todo::find($row['id'])->parent_id)->toBe($row['parent_id'] ?? null);
        }
    });

    it('still refuses one manual row too many in that request', function () {
        Todo::factory()->for($this->user)->count(451)->create();

        $this->postJson('/api/v1/import', frontendRequest(guestPlan(50, 150, 2)))
            ->assertUnprocessable()->assertJsonValidationErrors('todos');
        expect($this->user->todos()->count())->toBe(451);
    });

    it('refuses 500 manual rows plus generated rows in one request, then imports them in contract chunks', function () {
        $plan = guestPlan(500, 3, 2);

        // The current frontend sends all 509 to-dos at once: over the request cap, nothing written.
        $this->postJson('/api/v1/import', frontendRequest($plan))
            ->assertUnprocessable()->assertJsonValidationErrors('todos');
        expect(Todo::count())->toBe(0);

        importInChunks($plan);
        importInChunks($plan);

        expect(Todo::count())->toBe(509)->and(brokenReferences())->toBe(0);
    });
});

describe('existing account data', function () {
    it('does not count the account\'s own successors and their subtasks', function () {
        Todo::factory()->for($this->user)->count(298)->create();
        foreach (range(1, 50) as $_) {
            accountSuccessor($this->user, 2); // 1 manual predecessor + 3 exempt each
        }
        expect($this->user->todos()->count())->toBe(498); // 348 of them manual

        $todos = manualRows(152);
        $this->postJson('/api/v1/import', ['todos' => $todos])->assertOk();
        expect($this->user->todos()->count())->toBe(650);

        $this->postJson('/api/v1/import', ['todos' => [manualRow()]])
            ->assertUnprocessable()->assertJsonValidationErrors('todos');
    });

    it('exempts an account row once a new predecessor in the payload points at it', function () {
        Todo::factory()->for($this->user)->count(499)->create();
        $next = Todo::factory()->for($this->user)->create(['due_date' => '2026-10-27', 'repeat_rule' => ['type' => 'daily']]);

        $this->postJson('/api/v1/import', ['todos' => [
            manualRow(['due_date' => '2026-10-26', 'repeat_rule' => ['type' => 'daily'], 'done_at' => '2026-10-26T01:00:00Z', 'next_todo_id' => $next->id]),
        ]])->assertOk();
        expect($this->user->todos()->count())->toBe(501);
    });

    it('ignores another account\'s link to a row of this account', function () {
        $mine = Todo::factory()->for($this->user)->create();
        Todo::factory()->for($this->user)->count(499)->create();
        Todo::factory()->for(User::factory()->create())->create(['next_todo_id' => $mine->id]);

        $this->postJson('/api/v1/import', ['todos' => [manualRow()]])
            ->assertUnprocessable()->assertJsonValidationErrors('todos');
    });
});

describe('deleted rows', function () {
    it('skips the user\'s own deleted id on replay without counting it as new', function () {
        $deleted = Todo::factory()->for($this->user)->create(['title' => 'Gone']);
        $deleted->delete();
        Todo::factory()->for($this->user)->count(500)->create();

        $this->postJson('/api/v1/import', ['todos' => [['id' => $deleted->id, 'title' => 'Gone']]])
            ->assertOk()->assertJsonPath('data.todos', ['created' => 0, 'skipped' => 1]);

        expect(Todo::withTrashed()->find($deleted->id)->trashed())->toBeTrue();
    });

    it('does not count deleted to-dos toward the limit', function () {
        Todo::factory()->for($this->user)->count(10)->create()->each->delete();
        Todo::factory()->for($this->user)->count(499)->create();

        $this->postJson('/api/v1/import', ['todos' => [manualRow()]])->assertOk();
        expect($this->user->todos()->count())->toBe(500);
    });

    it('keeps exempting a successor whose predecessor was deleted', function () {
        $next = accountSuccessor($this->user);
        Todo::where('next_todo_id', $next->id)->first()->delete();
        Todo::factory()->for($this->user)->count(500)->create();

        $this->postJson('/api/v1/import', ['todos' => [manualRow(['parent_id' => $next->id])]])->assertOk();
    });
});

describe('chunks and retries', function () {
    it('keeps an accepted chunk after a later chunk is refused, and retries safely', function () {
        Todo::factory()->for($this->user)->count(100)->create();
        $first = manualRows(399);
        $second = manualRows(2);

        $this->postJson('/api/v1/import', ['todos' => $first])->assertOk();
        $this->postJson('/api/v1/import', ['todos' => $second])->assertUnprocessable()->assertJsonValidationErrors('todos');
        expect($this->user->todos()->count())->toBe(499)
            ->and(Todo::whereIn('id', array_column($second, 'id'))->count())->toBe(0);

        // A lost response: the client sends the first chunk again.
        $this->postJson('/api/v1/import', ['todos' => $first])
            ->assertOk()->assertJsonPath('data.todos', ['created' => 0, 'skipped' => 399]);
        $this->postJson('/api/v1/import', ['todos' => [$second[0]]])->assertOk();
        expect($this->user->todos()->count())->toBe(500);
    });

    it('replays a whole chunked plan without duplicates', function () {
        $plan = guestPlan(500, 501, 1);

        importInChunks($plan);
        importInChunks($plan);

        expect(Todo::count())->toBe(count($plan));
    });

    it('rolls back a chunk whose write fails and keeps the earlier chunks', function () {
        [$first, $second] = array_chunk(guestPlan(500, 1), Todo::MAX_PER_USER);
        $this->postJson('/api/v1/import', ['todos' => $first])->assertOk();

        Todo::creating(function (Todo $todo) use ($second) {
            if ($todo->id === end($second)['id']) {
                throw new RuntimeException('disk full');
            }
        });
        $this->postJson('/api/v1/import', ['todos' => $second])->assertStatus(500);

        expect(Todo::count())->toBe(count($first))
            ->and(Todo::whereIn('id', array_column($second, 'id'))->count())->toBe(0);
    });
});

describe('other accounts', function () {
    it('refuses another account\'s successor id and writes nothing', function () {
        $theirs = accountSuccessor(User::factory()->create());
        $plan = guestPlan(2, 1);
        $plan[0]['id'] = $theirs->id;
        $plan[1]['next_todo_id'] = $theirs->id;

        $this->postJson('/api/v1/import', ['todos' => $plan])
            ->assertUnprocessable()->assertJsonValidationErrors('todos.0.id');
        expect($this->user->todos()->count())->toBe(0);
    });

    it('refuses a link to another account\'s to-do', function () {
        $theirs = Todo::factory()->for(User::factory()->create())->create();

        $this->postJson('/api/v1/import', ['todos' => [
            manualRow(['due_date' => '2026-10-26', 'repeat_rule' => ['type' => 'daily'], 'next_todo_id' => $theirs->id]),
        ]])->assertUnprocessable()->assertJsonValidationErrors('todos.0.next_todo_id');
    });
});
