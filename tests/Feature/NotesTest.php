<?php

use App\Models\ScheduleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates, edits, deletes and restores an account note', function () {
    $user = User::factory()->create();
    $id = Str::uuid()->toString();
    $path = "/api/v1/notes/{$id}";
    $this->actingAs($user, 'sanctum');

    $this->postJson('/api/v1/notes', ['id' => $id])->assertUnprocessable();
    $this->postJson('/api/v1/notes', ['id' => $id, 'body' => 'Read chapter four'])
        ->assertCreated()
        ->assertJsonPath('data.id', $id);
    $this->patchJson($path, ['title' => 'Reading'])->assertOk()->assertJsonPath('data.title', 'Reading');
    $this->getJson('/api/v1/notes')->assertOk()->assertJsonCount(1, 'data');
    $this->deleteJson($path)->assertNoContent();
    $this->deleteJson($path)->assertNoContent();
    $this->getJson($path)->assertNotFound();
    $this->postJson($path.'/restore')->assertOk()->assertJsonPath('data.body', 'Read chapter four');
    $this->deleteJson($path)->assertNoContent();
    $this->travel(7)->days();
    $this->postJson($path.'/restore')->assertGone();
});

it('keeps note data and attachments scoped to the signed-in account', function () {
    $this->getJson('/api/v1/notes')->assertUnauthorized();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $item = ScheduleItem::factory()->for($owner)->create();
    $note = ['id' => Str::uuid()->toString(), 'title' => 'Occurrence', 'schedule_item_id' => $item->id, 'local_date' => '2026-11-23'];
    $this->actingAs($other, 'sanctum');
    $this->postJson('/api/v1/notes', $note)->assertUnprocessable()->assertJsonValidationErrors('schedule_item_id');

    $this->actingAs($owner, 'sanctum');
    $this->postJson('/api/v1/notes', $note)->assertCreated()->assertJsonPath('data.local_date', '2026-11-23');

    $this->actingAs($other, 'sanctum');
    $path = '/api/v1/notes/'.$note['id'];
    $this->getJson('/api/v1/notes')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($path)->assertNotFound();
    $this->patchJson($path, ['body' => 'Changed'])->assertNotFound();
    $this->deleteJson($path)->assertNotFound();
    $this->postJson($path.'/restore')->assertNotFound();
});

it('imports guest notes with schedule items and skips retries without overwriting edits', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');
    $itemId = Str::uuid()->toString();
    $noteId = Str::uuid()->toString();
    $payload = [
        'schedule_items' => [['id' => $itemId, 'title' => 'Study', 'start_time' => '08:00', 'end_time' => '09:00', 'days' => [0]]],
        'notes' => [['id' => $noteId, 'title' => 'Guest note', 'schedule_item_id' => $itemId, 'created_at' => '2026-11-23T09:00:00+07:00', 'updated_at' => '2026-11-24T09:00:00+07:00']],
    ];
    $this->postJson('/api/v1/import', $payload)->assertOk()->assertJsonPath('data.notes.created', 1);
    $this->getJson('/api/v1/notes/'.$noteId)->assertOk()
        ->assertJsonPath('data.created_at', '2026-11-23T02:00:00.000000Z')
        ->assertJsonPath('data.updated_at', '2026-11-24T02:00:00.000000Z');
    $this->patchJson('/api/v1/notes/'.$noteId, ['title' => 'Edited'])->assertOk();
    $this->postJson('/api/v1/import', $payload)->assertOk()->assertJsonPath('data.notes.skipped', 1);
    $this->getJson('/api/v1/notes/'.$noteId)->assertOk()->assertJsonPath('data.title', 'Edited');
});

it('rolls back a mixed import with an invalid block reference', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');
    $todoId = Str::uuid()->toString();
    $noteId = Str::uuid()->toString();
    $this->postJson('/api/v1/import', [
        'todos' => [['id' => $todoId, 'title' => 'Must roll back']],
        'notes' => [
            ['id' => $noteId, 'body' => 'Also rolls back'],
            ['id' => Str::uuid()->toString(), 'body' => 'Invalid attachment', 'schedule_item_id' => Str::uuid()->toString()],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors('notes.1.schedule_item_id');
    $this->assertDatabaseMissing('todos', ['id' => $todoId]);
    $this->assertDatabaseMissing('notes', ['id' => $noteId]);
});

it('preserves a note when its schedule block is deleted', function () {
    $user = User::factory()->create();
    $item = ScheduleItem::factory()->for($user)->create();
    $this->actingAs($user, 'sanctum');
    $noteId = Str::uuid()->toString();
    $this->postJson('/api/v1/notes', [
        'id' => $noteId,
        'body' => 'Keep this note',
        'schedule_item_id' => $item->id,
        'local_date' => '2026-11-23',
    ])->assertCreated();
    $this->deleteJson('/api/v1/schedule/items/'.$item->id)->assertNoContent();
    $this->getJson('/api/v1/notes/'.$noteId)->assertOk()
        ->assertJsonPath('data.schedule_item_id', null)
        ->assertJsonPath('data.local_date', null)
        ->assertJsonPath('data.body', 'Keep this note');
});

it('refuses migration rollback when notes exist', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');
    $noteId = Str::uuid()->toString();
    $this->postJson('/api/v1/notes', ['id' => $noteId, 'title' => 'Do not lose me'])->assertCreated();
    $migration = require database_path('migrations/2026_11_27_000000_create_notes_table.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'notes table contains data');
    $this->assertDatabaseHas('notes', ['id' => $noteId]);
});

it('removes active and deleted notes when the account is deleted', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $this->actingAs($user, 'sanctum');
    $activeId = Str::uuid()->toString();
    $deletedId = Str::uuid()->toString();
    foreach ([$activeId, $deletedId] as $id) {
        $this->postJson('/api/v1/notes', ['id' => $id, 'body' => 'Private'])->assertCreated();
    }
    $this->deleteJson('/api/v1/notes/'.$deletedId)->assertNoContent();
    $this->deleteJson('/api/v1/me', ['password' => 'correct-horse-battery'])->assertNoContent();
    $this->assertDatabaseMissing('notes', ['id' => $activeId]);
    $this->assertDatabaseMissing('notes', ['id' => $deletedId]);
});
