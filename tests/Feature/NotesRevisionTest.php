<?php

use App\Models\Note;
use App\Models\ScheduleItem;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user, 'sanctum');
});

function createNote(array $fields = []): array
{
    $note = ['id' => Str::uuid()->toString(), 'title' => 'Reading', 'body' => 'Chapter four', ...$fields];
    test()->postJson('/api/v1/notes', $note)->assertCreated()->assertJsonPath('data.revision', 1);

    return $note;
}

function noteRow(string $id): array
{
    return (array) DB::table('notes')->where('id', $id)->first();
}

it('adds a revision column that starts every note at 1', function () {
    expect(Schema::hasColumn('notes', 'revision'))->toBeTrue();
    $id = Str::uuid()->toString();
    DB::table('notes')->insert(['id' => $id, 'user_id' => $this->user->id, 'body' => 'Existing', 'created_at' => now(), 'updated_at' => now()]);

    expect(noteRow($id)['revision'])->toEqual(1);
    $this->getJson('/api/v1/notes/'.$id)->assertOk()->assertJsonPath('data.revision', 1);
});

it('backfills existing notes when the migration runs', function () {
    $migration = require database_path('migrations/2026_11_28_000000_add_revision_to_notes_table.php');
    $migration->down();
    $id = Str::uuid()->toString();
    DB::table('notes')->insert(['id' => $id, 'user_id' => $this->user->id, 'title' => 'Before', 'created_at' => now(), 'updated_at' => now()]);

    $migration->up();

    expect(noteRow($id))->toMatchArray(['title' => 'Before'])->and(noteRow($id)['revision'])->toEqual(1);
});

it('rejects a revision below 1 in the database', function () {
    $note = createNote();

    expect(fn () => DB::table('notes')->where('id', $note['id'])->update(['revision' => 0]))
        ->toThrow(QueryException::class);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'The CHECK constraint is PostgreSQL only.');

it('starts created and imported notes at 1 and ignores a client revision', function () {
    $created = ['id' => Str::uuid()->toString(), 'body' => 'Mine', 'revision' => 9];
    $this->postJson('/api/v1/notes', $created)->assertCreated()->assertJsonPath('data.revision', 1);
    $imported = ['id' => Str::uuid()->toString(), 'body' => 'Guest', 'revision' => 9];
    $this->postJson('/api/v1/import', ['notes' => [$imported]])->assertOk();

    $this->getJson('/api/v1/notes/'.$imported['id'])->assertJsonPath('data.revision', 1);
    $this->patchJson('/api/v1/notes/'.$created['id'], ['body' => 'Edited', 'revision' => 40])
        ->assertOk()->assertJsonPath('data.revision', 2);
    expect(noteRow($created['id'])['revision'])->toEqual(2);
});

it('returns the revision in list, show, update and restore responses', function () {
    $note = createNote();
    $path = '/api/v1/notes/'.$note['id'];

    $this->getJson('/api/v1/notes')->assertJsonPath('data.0.revision', 1);
    $this->getJson($path)->assertJsonPath('data.revision', 1);
    $this->patchJson($path, ['title' => 'Two'])->assertJsonPath('data.revision', 2);
    $this->deleteJson($path)->assertNoContent();
    $this->postJson($path.'/restore')->assertOk()->assertJsonPath('data.revision', 4);
});

it('applies a PATCH with the current revision and increments it once', function () {
    $note = createNote();

    $this->patchJson('/api/v1/notes/'.$note['id'], ['body' => 'New body', 'expected_revision' => 1])
        ->assertOk()
        ->assertJsonPath('data.body', 'New body')
        ->assertJsonPath('data.revision', 2);
});

it('rejects a stale PATCH with 409 and writes nothing', function () {
    $note = createNote();
    $this->patchJson('/api/v1/notes/'.$note['id'], ['title' => 'Other device'])->assertOk();
    $before = noteRow($note['id']);

    $this->patchJson('/api/v1/notes/'.$note['id'], ['title' => 'Stale', 'expected_revision' => 1])
        ->assertConflict()
        ->assertJsonStructure(['message']);

    expect(noteRow($note['id']))->toBe($before);
});

it('does not increment the revision for a PATCH that changes nothing', function () {
    $item = ScheduleItem::factory()->for($this->user)->create();
    $note = createNote(['schedule_item_id' => $item->id, 'local_date' => '2026-11-23']);
    $before = noteRow($note['id']);

    $this->patchJson('/api/v1/notes/'.$note['id'], ['title' => 'Reading', 'body' => 'Chapter four', 'local_date' => '2026-11-23', 'expected_revision' => 1])
        ->assertOk()->assertJsonPath('data.revision', 1);
    $this->patchJson('/api/v1/notes/'.$note['id'], ['expected_revision' => 1])
        ->assertOk()->assertJsonPath('data.revision', 1);

    expect(noteRow($note['id']))->toBe($before);
});

it('keeps legacy last-write-wins when expected_revision is omitted', function () {
    $note = createNote();
    $path = '/api/v1/notes/'.$note['id'];

    $this->patchJson($path, ['title' => 'First'])->assertOk()->assertJsonPath('data.revision', 2);
    $this->patchJson($path, ['title' => 'Second'])->assertOk()->assertJsonPath('data.revision', 3);
    $this->deleteJson($path)->assertNoContent();
    expect(noteRow($note['id'])['revision'])->toEqual(4);
});

it('validates expected_revision', function (mixed $value) {
    $note = createNote();
    $path = '/api/v1/notes/'.$note['id'];

    $this->patchJson($path, ['title' => 'X', 'expected_revision' => $value])
        ->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
    $this->deleteJson($path.'?'.http_build_query(['expected_revision' => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors('expected_revision');

    expect(noteRow($note['id']))->toMatchArray(['title' => 'Reading', 'deleted_at' => null])
        ->and(noteRow($note['id'])['revision'])->toEqual(1);
})->with([
    'zero' => [0],
    'negative' => [-1],
    'fraction' => [1.5],
    'text' => ['one'],
    'empty' => [''],
]);

it('deletes with the current revision and increments it', function () {
    $note = createNote();

    $this->deleteJson('/api/v1/notes/'.$note['id'].'?expected_revision=1')->assertNoContent();

    expect(noteRow($note['id'])['deleted_at'])->not->toBeNull()
        ->and(noteRow($note['id'])['revision'])->toEqual(2);
});

it('rejects a stale DELETE with 409 and keeps the note', function () {
    $note = createNote();
    $this->patchJson('/api/v1/notes/'.$note['id'], ['title' => 'Other device'])->assertOk();

    $this->deleteJson('/api/v1/notes/'.$note['id'].'?expected_revision=1')->assertConflict();

    $this->getJson('/api/v1/notes/'.$note['id'])->assertOk()->assertJsonPath('data.revision', 2);
});

it('keeps DELETE idempotent for an already deleted note', function () {
    $note = createNote();
    $path = '/api/v1/notes/'.$note['id'];
    $this->deleteJson($path)->assertNoContent();

    $this->deleteJson($path)->assertNoContent();
    $this->deleteJson($path.'?expected_revision=1')->assertNoContent();
    $this->deleteJson($path.'?expected_revision=2')->assertNoContent();

    expect(noteRow($note['id'])['revision'])->toEqual(2);
});

it('increments the revision on restore only when the note was deleted', function () {
    $note = createNote();
    $path = '/api/v1/notes/'.$note['id'];

    $this->postJson($path.'/restore')->assertOk()->assertJsonPath('data.revision', 1);
    $this->deleteJson($path)->assertNoContent();
    $this->postJson($path.'/restore')->assertOk()->assertJsonPath('data.revision', 3);
});

it('detaches notes from a deleted schedule block and increments their revisions', function () {
    $item = ScheduleItem::factory()->for($this->user)->create();
    $other = ScheduleItem::factory()->for($this->user)->create();
    $active = createNote(['schedule_item_id' => $item->id, 'local_date' => '2026-11-23']);
    $deleted = createNote(['schedule_item_id' => $item->id, 'local_date' => '2026-11-24']);
    $this->deleteJson('/api/v1/notes/'.$deleted['id'])->assertNoContent();
    $unrelated = createNote(['schedule_item_id' => $other->id]);
    $standalone = createNote();
    $untouched = [noteRow($unrelated['id']), noteRow($standalone['id'])];
    $this->travel(1)->minutes();

    $this->deleteJson('/api/v1/schedule/items/'.$item->id)->assertNoContent();

    $this->getJson('/api/v1/notes/'.$active['id'])->assertOk()
        ->assertJsonPath('data.schedule_item_id', null)
        ->assertJsonPath('data.local_date', null)
        ->assertJsonPath('data.revision', 2);
    expect(noteRow($active['id']))->toMatchArray(['schedule_item_id' => null, 'local_date' => null])
        ->and(noteRow($deleted['id']))->toMatchArray(['schedule_item_id' => null, 'local_date' => null])
        ->and(noteRow($deleted['id'])['revision'])->toEqual(3)
        ->and(noteRow($deleted['id'])['deleted_at'])->not->toBeNull()
        ->and(Note::withTrashed()->findOrFail($deleted['id'])->updated_at->gt(now()->subSeconds(5)))->toBeTrue()
        ->and([noteRow($unrelated['id']), noteRow($standalone['id'])])->toBe($untouched);
});

it('rolls back the block delete and every note change together', function () {
    $item = ScheduleItem::factory()->for($this->user)->create();
    $note = createNote(['schedule_item_id' => $item->id, 'local_date' => '2026-11-23']);
    $before = noteRow($note['id']);
    ScheduleItem::deleting(fn () => throw new RuntimeException('Simulated failure'));

    $this->withoutExceptionHandling();
    expect(fn () => $this->deleteJson('/api/v1/schedule/items/'.$item->id))->toThrow(RuntimeException::class);

    expect(noteRow($note['id']))->toBe($before);
    $this->assertDatabaseHas('schedule_items', ['id' => $item->id]);
});

it('rolls back a PATCH that fails after the revision check', function () {
    $note = createNote();
    $before = noteRow($note['id']);

    $this->patchJson('/api/v1/notes/'.$note['id'], ['title' => null, 'body' => null, 'expected_revision' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('body');
    $this->patchJson('/api/v1/notes/'.$note['id'], ['schedule_item_id' => Str::uuid()->toString(), 'expected_revision' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('schedule_item_id');

    expect(noteRow($note['id']))->toBe($before);
});

it('keeps other accounts and deleted notes unreachable for conditional writes', function () {
    $note = createNote();
    $path = '/api/v1/notes/'.$note['id'];
    $this->actingAs(User::factory()->create(), 'sanctum');

    $this->patchJson($path, ['title' => 'Probe', 'expected_revision' => 1])->assertNotFound();
    $this->patchJson($path, ['title' => 'Probe', 'expected_revision' => 7])->assertNotFound();
    $this->deleteJson($path.'?expected_revision=1')->assertNotFound();
    $this->deleteJson($path.'?expected_revision=7')->assertNotFound();

    $this->actingAs($this->user, 'sanctum');
    $this->deleteJson($path)->assertNoContent();
    $this->patchJson($path, ['title' => 'Gone', 'expected_revision' => 2])->assertNotFound();
    $this->patchJson($path, ['title' => 'Gone', 'expected_revision' => 1])->assertNotFound();
    expect(noteRow($note['id']))->toMatchArray(['title' => 'Reading'])->and(noteRow($note['id'])['revision'])->toEqual(2);
});

it('answers an identical POST replay with 200 and the existing note', function () {
    $item = ScheduleItem::factory()->for($this->user)->create();
    $note = createNote(['schedule_item_id' => $item->id, 'local_date' => '2026-11-23']);
    $before = noteRow($note['id']);

    $this->postJson('/api/v1/notes', [...$note, 'id' => strtoupper($note['id']), 'schedule_item_id' => strtoupper($item->id), 'revision' => 5])
        ->assertOk()
        ->assertJsonPath('data.id', $note['id'])
        ->assertJsonPath('data.revision', 1);

    expect(noteRow($note['id']))->toBe($before)->and(Note::withTrashed()->count())->toBe(1);
});

it('answers a changed POST replay with 409 and keeps the note', function (array $change) {
    $note = createNote();
    $before = noteRow($note['id']);

    $this->postJson('/api/v1/notes', [...$note, ...$change])->assertConflict();

    expect(noteRow($note['id']))->toBe($before);
})->with([
    'title' => [['title' => 'Other']],
    'body' => [['body' => null]],
]);

it('answers a POST replay of an edited note with 409', function () {
    $note = createNote();
    $this->patchJson('/api/v1/notes/'.$note['id'], ['title' => 'Edited'])->assertOk();

    $this->postJson('/api/v1/notes', $note)->assertConflict();
    $this->getJson('/api/v1/notes/'.$note['id'])->assertJsonPath('data.title', 'Edited');
});

it('never revives a deleted note through a POST replay', function () {
    $note = createNote();
    $this->deleteJson('/api/v1/notes/'.$note['id'])->assertNoContent();
    $before = noteRow($note['id']);

    $this->postJson('/api/v1/notes', $note)->assertConflict();
    $this->postJson('/api/v1/notes', [...$note, 'body' => 'Changed'])->assertConflict();

    expect(noteRow($note['id']))->toBe($before);
    $this->getJson('/api/v1/notes/'.$note['id'])->assertNotFound();
});

it('keeps another account\'s UUID a conflict on POST', function () {
    $note = createNote();
    $this->actingAs(User::factory()->create(), 'sanctum');

    $this->postJson('/api/v1/notes', $note)->assertConflict()->assertJsonMissingPath('data');
    $this->getJson('/api/v1/notes/'.$note['id'])->assertNotFound();
});

it('lets a client reconcile a lost PATCH response', function () {
    $note = createNote();
    $edit = ['body' => 'Saved once', 'expected_revision' => 1];
    $path = '/api/v1/notes/'.$note['id'];

    $this->patchJson($path, $edit)->assertOk();
    $this->patchJson($path, $edit)->assertConflict();

    $this->getJson($path)->assertOk()->assertJsonPath('data.body', 'Saved once')->assertJsonPath('data.revision', 2);
});

it('lets a client save a guest copy as a separate note and retry safely', function () {
    $account = createNote();
    $guest = ['id' => Str::uuid()->toString(), 'title' => 'Reading', 'body' => 'Guest version'];

    $this->postJson('/api/v1/notes', $guest)->assertCreated();
    $this->postJson('/api/v1/notes', $guest)->assertOk()->assertJsonPath('data.id', $guest['id']);

    $this->getJson('/api/v1/notes/'.$account['id'])->assertJsonPath('data.body', 'Chapter four');
    expect(Note::count())->toBe(2);
});

it('keeps B-052 import diagnostics unchanged', function () {
    $identical = createNote();
    $different = createNote();
    $deleted = createNote();
    $this->patchJson('/api/v1/notes/'.$different['id'], ['title' => 'Account edit'])->assertOk();
    $this->deleteJson('/api/v1/notes/'.$deleted['id'])->assertNoContent();
    $before = array_map(fn ($note) => noteRow($note['id']), [$identical, $different, $deleted]);

    $this->postJson('/api/v1/import', ['notes' => [$identical, $different, $deleted]])
        ->assertOk()
        ->assertJsonPath('data.notes', ['created' => 0, 'skipped' => 3, 'skipped_details' => [
            ['id' => $identical['id'], 'status' => 'identical'],
            ['id' => $different['id'], 'status' => 'different'],
            ['id' => $deleted['id'], 'status' => 'deleted'],
        ]]);

    expect(array_map(fn ($note) => noteRow($note['id']), [$identical, $different, $deleted]))->toBe($before);
});
