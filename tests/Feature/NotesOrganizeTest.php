<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(), 'sanctum');
});

it('defaults new notes to unpinned, uncolored and untagged', function () {
    $this->postJson('/api/v1/notes', ['id' => Str::uuid()->toString(), 'body' => 'Plain'])
        ->assertCreated()
        ->assertJsonPath('data.pinned', false)
        ->assertJsonPath('data.color', null)
        ->assertJsonPath('data.tags', []);
});

it('stores pin, color and normalized tags', function () {
    $this->postJson('/api/v1/notes', [
        'id' => Str::uuid()->toString(),
        'title' => 'Ideas',
        'pinned' => true,
        'color' => 'study',
        'tags' => [' Work ', '#work', 'Ideas', '#'],
    ])
        ->assertCreated()
        ->assertJsonPath('data.pinned', true)
        ->assertJsonPath('data.color', 'study')
        ->assertJsonPath('data.tags', ['work', 'ideas']);
});

it('rejects unknown colors, too many tags and long tags', function (array $fields, string $error) {
    $this->postJson('/api/v1/notes', ['id' => Str::uuid()->toString(), 'body' => 'x', ...$fields])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($error);
})->with([
    'unknown color' => [['color' => 'red'], 'color'],
    'six tags' => [['tags' => ['a', 'b', 'c', 'd', 'e', 'f']], 'tags'],
    'long tag' => [['tags' => [str_repeat('a', 25)]], 'tags.0'],
    'blank tag' => [['tags' => ['   ']], 'tags.0'],
    'tags not a list' => [['tags' => ['key' => 'value']], 'tags'],
    'pinned not boolean' => [['pinned' => 'yes'], 'pinned'],
]);

it('lists pinned notes first, then the most recently updated', function () {
    $ids = [];
    foreach (['old pinned' => true, 'newer' => false, 'newest' => false] as $title => $pinned) {
        $ids[$title] = Str::uuid()->toString();
        $this->postJson('/api/v1/notes', ['id' => $ids[$title], 'title' => $title, 'pinned' => $pinned])->assertCreated();
        $this->travel(1)->minutes();
    }

    $this->getJson('/api/v1/notes')
        ->assertOk()
        ->assertJsonPath('data.*.title', ['old pinned', 'newest', 'newer']);
});

it('bumps the revision when pin, color or tags change, and not on a no-op', function () {
    $id = Str::uuid()->toString();
    $path = "/api/v1/notes/{$id}";
    $this->postJson('/api/v1/notes', ['id' => $id, 'body' => 'x', 'tags' => ['work']])->assertCreated();

    $this->patchJson($path, ['pinned' => true, 'expected_revision' => 1])->assertOk()->assertJsonPath('data.revision', 2);
    $this->patchJson($path, ['color' => 'rest'])->assertOk()->assertJsonPath('data.revision', 3);
    $this->patchJson($path, ['tags' => ['#Work']])->assertOk()->assertJsonPath('data.revision', 3);
    $this->patchJson($path, ['tags' => []])->assertOk()->assertJsonPath('data.revision', 4)->assertJsonPath('data.tags', []);
    $this->patchJson($path, ['color' => null, 'pinned' => false])->assertOk()->assertJsonPath('data.revision', 5);
});

it('treats a create replay as identical only when pin, color and tags match', function () {
    $note = ['id' => Str::uuid()->toString(), 'title' => 'Same', 'pinned' => true, 'tags' => ['work']];
    $this->postJson('/api/v1/notes', $note)->assertCreated();

    $this->postJson('/api/v1/notes', [...$note, 'tags' => ['#Work']])->assertOk();
    $this->postJson('/api/v1/notes', [...$note, 'tags' => ['home']])->assertConflict();
    $this->postJson('/api/v1/notes', [...$note, 'pinned' => false])->assertConflict();
});

it('imports guest pin, color and tags and reports a tag-only difference', function () {
    $id = Str::uuid()->toString();
    $guest = ['id' => $id, 'title' => 'Guest', 'pinned' => true, 'color' => 'life', 'tags' => ['Home']];

    $this->postJson('/api/v1/import', ['notes' => [$guest]])->assertOk()->assertJsonPath('data.notes.created', 1);
    $this->getJson("/api/v1/notes/{$id}")
        ->assertOk()
        ->assertJsonPath('data.pinned', true)
        ->assertJsonPath('data.color', 'life')
        ->assertJsonPath('data.tags', ['home']);

    $this->postJson('/api/v1/import', ['notes' => [$guest]])
        ->assertOk()
        ->assertJsonPath('data.notes.skipped_details.0.status', 'identical');
    $this->postJson('/api/v1/import', ['notes' => [[...$guest, 'tags' => ['work']]]])
        ->assertOk()
        ->assertJsonPath('data.notes.skipped_details.0.status', 'different');
});
