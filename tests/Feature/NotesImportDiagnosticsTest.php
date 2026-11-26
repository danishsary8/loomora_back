<?php

use App\Models\Note;
use App\Models\ScheduleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function guestNote(array $fields = []): array
{
    return ['id' => Str::uuid()->toString(), 'title' => 'Guest note', 'body' => 'Read chapter four', ...$fields];
}

function importNotes(array $notes, array $extra = [])
{
    return test()->postJson('/api/v1/import', ['notes' => $notes, ...$extra]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user, 'sanctum');
});

it('reports no diagnostics for new notes', function () {
    importNotes([guestNote(), guestNote()])
        ->assertOk()
        ->assertExactJson(['data' => [
            'todos' => ['created' => 0, 'skipped' => 0],
            'schedule_items' => ['created' => 0, 'skipped' => 0],
            'exceptions' => ['created' => 0, 'skipped' => 0],
            'checkins' => ['created' => 0, 'skipped' => 0],
            'daily_intentions' => ['created' => 0, 'skipped' => 0],
            'notes' => ['created' => 2, 'skipped' => 0, 'skipped_details' => []],
        ]]);
});

it('omits the notes section when the request has no notes', function () {
    $this->postJson('/api/v1/import', ['todos' => []])->assertOk()->assertJsonMissingPath('data.notes');
});

it('reports an unchanged retry as identical', function () {
    $note = guestNote();
    importNotes([$note])->assertOk();

    importNotes([$note])->assertOk()->assertExactJson(['data' => [
        'todos' => ['created' => 0, 'skipped' => 0],
        'schedule_items' => ['created' => 0, 'skipped' => 0],
        'exceptions' => ['created' => 0, 'skipped' => 0],
        'checkins' => ['created' => 0, 'skipped' => 0],
        'daily_intentions' => ['created' => 0, 'skipped' => 0],
        'notes' => ['created' => 0, 'skipped' => 1, 'skipped_details' => [['id' => $note['id'], 'status' => 'identical']]],
    ]]);
});

it('reports a guest edit as different without touching the account note', function () {
    $note = guestNote();
    importNotes([$note])->assertOk();
    $before = Note::findOrFail($note['id'])->getAttributes();

    importNotes([[...$note, 'body' => 'Edited on the device', 'updated_at' => '2030-01-01T00:00:00Z']])
        ->assertOk()
        ->assertJsonPath('data.notes', ['created' => 0, 'skipped' => 1, 'skipped_details' => [['id' => $note['id'], 'status' => 'different']]]);

    expect(Note::findOrFail($note['id'])->getAttributes())->toBe($before);
});

it('reports an account edit as different', function () {
    $note = guestNote();
    importNotes([$note])->assertOk();
    $this->patchJson('/api/v1/notes/'.$note['id'], ['title' => 'Account edit'])->assertOk();

    importNotes([$note])->assertOk()
        ->assertJsonPath('data.notes.skipped_details', [['id' => $note['id'], 'status' => 'different']]);
    $this->getJson('/api/v1/notes/'.$note['id'])->assertJsonPath('data.title', 'Account edit');
});

it('compares each visible field', function (array $change) {
    $item = ScheduleItem::factory()->for($this->user)->create();
    $other = ScheduleItem::factory()->for($this->user)->create();
    $note = guestNote(['schedule_item_id' => $item->id, 'local_date' => '2026-11-23']);
    importNotes([$note])->assertOk();
    $changed = [...$note, ...$change];
    if (($change['schedule_item_id'] ?? null) === 'other') {
        $changed['schedule_item_id'] = $other->id;
    }

    importNotes([$changed])->assertOk()->assertJsonPath('data.notes.skipped_details.0.status', 'different');
})->with([
    'title' => [['title' => 'Other title']],
    'title cleared' => [['title' => null]],
    'body' => [['body' => 'Other body']],
    'block' => [['schedule_item_id' => 'other']],
    'block removed' => [['schedule_item_id' => null, 'local_date' => null]],
    'occurrence' => [['local_date' => '2026-11-24']],
    'occurrence removed' => [['local_date' => null]],
]);

it('ignores timestamps, UUID case and the hidden date of a standalone note', function () {
    $item = ScheduleItem::factory()->for($this->user)->create();
    $kept = ScheduleItem::factory()->for($this->user)->create();
    $attached = guestNote(['schedule_item_id' => $kept->id, 'local_date' => '2026-11-23']);
    $standalone = guestNote();
    // The block delete keeps the stored date, which the API hides once the note is standalone.
    $detached = guestNote(['schedule_item_id' => $item->id, 'local_date' => '2026-11-23']);
    importNotes([$attached, $standalone, $detached])->assertOk();
    $this->deleteJson('/api/v1/schedule/items/'.$item->id)->assertNoContent();

    importNotes([
        [...$attached, 'id' => strtoupper($attached['id'])],
        [...$standalone, 'created_at' => '2020-01-01T00:00:00Z', 'updated_at' => '2030-01-01T00:00:00Z'],
        [...$detached, 'schedule_item_id' => null, 'local_date' => '2026-11-23'],
    ])->assertOk()->assertJsonPath('data.notes.skipped_details', [
        ['id' => $attached['id'], 'status' => 'identical'],
        ['id' => $standalone['id'], 'status' => 'identical'],
        ['id' => $detached['id'], 'status' => 'identical'],
    ]);
});

it('ignores the letter case of a retried block id', function () {
    $item = ScheduleItem::factory()->for($this->user)->create();
    $note = guestNote(['schedule_item_id' => $item->id, 'local_date' => '2026-11-23']);
    importNotes([$note])->assertOk();

    importNotes([[...$note, 'schedule_item_id' => strtoupper($item->id)]])->assertOk()
        ->assertJsonPath('data.notes.skipped_details.0.status', 'identical');
});

it('reports a deleted account note as deleted and keeps it deleted', function () {
    $note = guestNote();
    importNotes([$note])->assertOk();
    $this->deleteJson('/api/v1/notes/'.$note['id'])->assertNoContent();

    foreach ([$note, [...$note, 'body' => 'Edited on the device']] as $retry) {
        importNotes([$retry])->assertOk()
            ->assertJsonPath('data.notes.skipped_details', [['id' => $note['id'], 'status' => 'deleted']]);
    }

    $this->getJson('/api/v1/notes/'.$note['id'])->assertNotFound();
    expect(Note::withTrashed()->findOrFail($note['id'])->body)->toBe('Read chapter four');
});

it('reports one diagnostic per skipped note in submission order', function () {
    [$identical, $different, $deleted] = [guestNote(), guestNote(), guestNote()];
    importNotes([$identical, $different, $deleted])->assertOk();
    $this->deleteJson('/api/v1/notes/'.$deleted['id'])->assertNoContent();
    $new = guestNote();

    importNotes([$deleted, $new, [...$different, 'title' => 'Changed'], $identical])
        ->assertOk()
        ->assertJsonPath('data.notes', ['created' => 1, 'skipped' => 3, 'skipped_details' => [
            ['id' => $deleted['id'], 'status' => 'deleted'],
            ['id' => $different['id'], 'status' => 'different'],
            ['id' => $identical['id'], 'status' => 'identical'],
        ]]);
});

it('stays bounded by the 500-note request limit', function () {
    $notes = array_map(fn () => guestNote(), range(1, Note::MAX_IMPORT));
    importNotes($notes)->assertOk();

    importNotes($notes)->assertOk()
        ->assertJsonPath('data.notes.skipped', Note::MAX_IMPORT)
        ->assertJsonCount(Note::MAX_IMPORT, 'data.notes.skipped_details');
    importNotes([...$notes, guestNote()])->assertUnprocessable()->assertJsonValidationErrors('notes');
});

it('rejects another account\'s note id instead of reporting it', function () {
    $foreign = guestNote();
    $this->actingAs(User::factory()->create(), 'sanctum');
    importNotes([$foreign])->assertOk();
    $this->actingAs($this->user, 'sanctum');
    $own = guestNote();

    importNotes([$own, [...$foreign, 'body' => 'Probe']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('notes.1.id')
        ->assertJsonMissingPath('data');
    $this->assertDatabaseMissing('notes', ['id' => $own['id']]);
    expect(Note::findOrFail($foreign['id'])->body)->toBe('Read chapter four');
});

it('rolls back the whole request and reports nothing when a later row fails', function () {
    $existing = guestNote();
    importNotes([$existing])->assertOk();
    $new = guestNote();
    $todoId = Str::uuid()->toString();

    importNotes(
        [[...$existing, 'body' => 'Edited'], $new, guestNote(['schedule_item_id' => Str::uuid()->toString()])],
        ['todos' => [['id' => $todoId, 'title' => 'Must roll back']]],
    )->assertUnprocessable()->assertJsonValidationErrors('notes.2.schedule_item_id')->assertJsonMissingPath('data');

    $this->assertDatabaseMissing('notes', ['id' => $new['id']]);
    $this->assertDatabaseMissing('todos', ['id' => $todoId]);
    expect(Note::findOrFail($existing['id'])->body)->toBe('Read chapter four');
});
