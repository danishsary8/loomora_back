<?php

use App\Models\FocusSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

const DELETED_FOCUS_SESSION_ID = '0b9c2f4e-7a1d-4c3b-9e8f-2a6b5c4d3e21';

/** A finished 25-minute countdown that started at 09:00 in Phnom Penh. */
function deletableFocusSession(array $overrides = []): array
{
    return [
        'id' => DELETED_FOCUS_SESSION_ID,
        'mode' => 'countdown',
        'started_at' => '2026-11-23T02:00:00.000Z',
        'ended_at' => '2026-11-23T02:25:00.000Z',
        'end_reason' => 'completed',
        'planned_focus_ms' => 1_500_000,
        'label' => 'Read chapter 4',
        'segments' => [
            ['type' => 'focus', 'started_at' => '2026-11-23T02:00:00.000Z', 'ended_at' => '2026-11-23T02:25:00.000Z', 'active_ms' => 1_500_000],
        ],
        'laps' => null,
        ...$overrides,
    ];
}

beforeEach(function () {
    Carbon::setTestNow('2026-11-23 04:00:00');
    $this->user = User::factory()->create(['timezone' => 'Asia/Phnom_Penh', 'password' => 'correct-horse-battery']);
    $this->actingAs($this->user, 'sanctum');
    $this->postJson('/api/v1/focus-sessions', deletableFocusSession())->assertCreated();
});

describe('delete', function () {
    it('hides the session from the day list and from show', function () {
        $this->deleteJson('/api/v1/focus-sessions/'.DELETED_FOCUS_SESSION_ID)->assertNoContent();

        $this->getJson('/api/v1/focus-sessions?date=2026-11-23')->assertOk()->assertExactJson(['data' => []]);
        $this->getJson('/api/v1/focus-sessions/'.DELETED_FOCUS_SESSION_ID)->assertNotFound();
        expect(FocusSession::withTrashed()->find(DELETED_FOCUS_SESSION_ID)?->trashed())->toBeTrue();
    });

    it('answers 204 again for a session that is already deleted and keeps the first deletion time', function () {
        $this->deleteJson('/api/v1/focus-sessions/'.DELETED_FOCUS_SESSION_ID)->assertNoContent();
        $first = FocusSession::withTrashed()->find(DELETED_FOCUS_SESSION_ID)->deleted_at;

        Carbon::setTestNow('2026-11-23 05:00:00');
        $this->deleteJson('/api/v1/focus-sessions/'.DELETED_FOCUS_SESSION_ID)->assertNoContent();

        expect(FocusSession::withTrashed()->find(DELETED_FOCUS_SESSION_ID)->deleted_at->equalTo($first))->toBeTrue();
    });

    it('answers 404 for another user\'s session and for an unknown id, and changes nothing', function () {
        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->deleteJson('/api/v1/focus-sessions/'.DELETED_FOCUS_SESSION_ID)->assertNotFound();
        $this->deleteJson('/api/v1/focus-sessions/9d2e6c1a-4b3f-4e5d-8c7b-6a5f4e3d2c1b')->assertNotFound();

        expect(FocusSession::find(DELETED_FOCUS_SESSION_ID))->not->toBeNull();
    });

    it('requires authentication for delete and restore', function () {
        $this->app['auth']->forgetGuards();

        $this->deleteJson('/api/v1/focus-sessions/'.DELETED_FOCUS_SESSION_ID)->assertUnauthorized();
        $this->postJson('/api/v1/focus-sessions/'.DELETED_FOCUS_SESSION_ID.'/restore')->assertUnauthorized();
    });
});

describe('restore', function () {
    it('brings back the same session', function () {
        $this->deleteJson('/api/v1/focus-sessions/'.DELETED_FOCUS_SESSION_ID)->assertNoContent();

        $this->postJson('/api/v1/focus-sessions/'.DELETED_FOCUS_SESSION_ID.'/restore')
            ->assertOk()
            ->assertJsonPath('data.id', DELETED_FOCUS_SESSION_ID)
            ->assertJsonPath('data.label', 'Read chapter 4');

        $this->getJson('/api/v1/focus-sessions?date=2026-11-23')->assertOk()->assertJsonPath('data.0.id', DELETED_FOCUS_SESSION_ID);
        expect(FocusSession::find(DELETED_FOCUS_SESSION_ID)?->trashed())->toBeFalse();
    });

    it('works just under 7 days and returns 410 after 8 days', function () {
        $this->deleteJson('/api/v1/focus-sessions/'.DELETED_FOCUS_SESSION_ID)->assertNoContent();

        Carbon::setTestNow(Carbon::parse('2026-11-23 04:00:00')->addDays(7)->subMinute());
        $this->postJson('/api/v1/focus-sessions/'.DELETED_FOCUS_SESSION_ID.'/restore')->assertOk();

        $this->deleteJson('/api/v1/focus-sessions/'.DELETED_FOCUS_SESSION_ID)->assertNoContent();
        Carbon::setTestNow(Carbon::now()->addDays(8));
        $this->postJson('/api/v1/focus-sessions/'.DELETED_FOCUS_SESSION_ID.'/restore')
            ->assertStatus(410)
            ->assertJsonPath('message', 'This Focus session was deleted more than 7 days ago and can no longer be restored.');

        expect(FocusSession::withTrashed()->find(DELETED_FOCUS_SESSION_ID)?->trashed())->toBeTrue();
    });

    it('returns a session that is not deleted unchanged', function () {
        $this->postJson('/api/v1/focus-sessions/'.DELETED_FOCUS_SESSION_ID.'/restore')
            ->assertOk()
            ->assertJsonPath('data.id', DELETED_FOCUS_SESSION_ID);
    });

    it('answers 404 for another user\'s deleted session', function () {
        $this->deleteJson('/api/v1/focus-sessions/'.DELETED_FOCUS_SESSION_ID)->assertNoContent();
        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->postJson('/api/v1/focus-sessions/'.DELETED_FOCUS_SESSION_ID.'/restore')->assertNotFound();

        expect(FocusSession::withTrashed()->find(DELETED_FOCUS_SESSION_ID)?->trashed())->toBeTrue();
    });
});

describe('replay after delete (D3)', function () {
    it('returns 200 with the stored session for an identical retry and keeps it deleted', function () {
        $this->deleteJson('/api/v1/focus-sessions/'.DELETED_FOCUS_SESSION_ID)->assertNoContent();

        $this->postJson('/api/v1/focus-sessions', deletableFocusSession())
            ->assertOk()
            ->assertJsonPath('data.id', DELETED_FOCUS_SESSION_ID);

        expect(FocusSession::withTrashed()->count())->toBe(1)
            ->and(FocusSession::withTrashed()->find(DELETED_FOCUS_SESSION_ID)?->trashed())->toBeTrue();
        $this->getJson('/api/v1/focus-sessions/'.DELETED_FOCUS_SESSION_ID)->assertNotFound();
    });

    it('answers 409 for a changed payload and keeps the deleted row unchanged', function () {
        $this->deleteJson('/api/v1/focus-sessions/'.DELETED_FOCUS_SESSION_ID)->assertNoContent();

        $this->postJson('/api/v1/focus-sessions', deletableFocusSession(['label' => 'Something else']))->assertConflict();

        $stored = FocusSession::withTrashed()->find(DELETED_FOCUS_SESSION_ID);
        expect($stored?->label)->toBe('Read chapter 4')
            ->and($stored?->trashed())->toBeTrue();
    });

    it('answers the same 409 to another account replaying a deleted session\'s UUID', function () {
        $this->deleteJson('/api/v1/focus-sessions/'.DELETED_FOCUS_SESSION_ID)->assertNoContent();
        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->postJson('/api/v1/focus-sessions', deletableFocusSession())->assertConflict();

        expect(FocusSession::withTrashed()->count())->toBe(1);
    });
});

it('removes every session of the user with the account, including deleted ones', function () {
    $this->deleteJson('/api/v1/focus-sessions/'.DELETED_FOCUS_SESSION_ID)->assertNoContent();
    $this->postJson('/api/v1/focus-sessions', deletableFocusSession(['id' => '5a4b3c2d-1e0f-4a9b-8c7d-6e5f4a3b2c1d']))->assertCreated();

    $this->deleteJson('/api/v1/me', ['password' => 'correct-horse-battery'])->assertNoContent();

    expect(FocusSession::withTrashed()->where('user_id', $this->user->id)->count())->toBe(0);
});
