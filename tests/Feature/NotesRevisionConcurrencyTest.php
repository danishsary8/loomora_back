<?php

use App\Models\Note;
use App\Models\User;
use App\Services\NoteService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RowLockRace;

uses(DatabaseMigrations::class);

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires independent PostgreSQL connections.');
    }
});

// The B-048 migration refuses to roll back while notes exist, and DatabaseMigrations rolls back after each test.
afterEach(fn () => DB::table('notes')->delete());

it('lets exactly one of two PATCHes with the same revision win', function () {
    $user = User::factory()->create();
    $id = Str::uuid()->toString();
    app(NoteService::class)->create($user, ['id' => $id, 'body' => 'Original']);

    $response = RowLockRace::run($user, 'PATCH', "/api/v1/notes/{$id}", ['body' => 'Second device', 'expected_revision' => 1],
        fn () => app(NoteService::class)->update($user, $id, ['body' => 'First device'], 1));

    expect($response['status'])->toBe(409)
        ->and(Note::findOrFail($id))->toMatchArray(['body' => 'First device', 'revision' => 2]);
});

it('rejects a DELETE that waited behind a PATCH with the same revision', function () {
    $user = User::factory()->create();
    $id = Str::uuid()->toString();
    app(NoteService::class)->create($user, ['id' => $id, 'body' => 'Original']);

    $response = RowLockRace::run($user, 'DELETE', "/api/v1/notes/{$id}?expected_revision=1", [],
        fn () => app(NoteService::class)->update($user, $id, ['body' => 'Edited'], 1));

    expect($response['status'])->toBe(409)
        ->and(Note::find($id))->not->toBeNull()
        ->and(Note::findOrFail($id)->revision)->toBe(2);
});
