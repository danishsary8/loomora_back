<?php

use App\Models\ScheduleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    // 2026-10-24 23:30 UTC is already 2026-10-25 in Phnom Penh (UTC+7).
    Carbon::setTestNow('2026-10-24 23:30:00');
    $this->user = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
    $this->actingAs($this->user, 'sanctum');
});

function classPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Math class',
        'category' => 'study',
        'start_time' => '08:00',
        'end_time' => '09:30',
        'days' => [0, 2, 4],
    ], $overrides);
}

describe('create', function () {
    it('creates one item for several days', function () {
        $response = $this->postJson('/api/v1/schedule/items', classPayload())
            ->assertCreated()
            ->assertJsonPath('data.title', 'Math class')
            ->assertJsonPath('data.start_time', '08:00')
            ->assertJsonPath('data.end_time', '09:30')
            ->assertJsonPath('data.days', [0, 2, 4])
            ->assertJsonPath('data.ends_on', null);

        expect(Str::isUuid($response->json('data.id')))->toBeTrue()
            ->and(ScheduleItem::count())->toBe(1);
    });

    it('defaults starts_on to today in the user time zone and category to other', function () {
        $this->postJson('/api/v1/schedule/items', classPayload(['category' => null]) + [])
            ->assertUnprocessable();

        $payload = classPayload();
        unset($payload['category']);

        $this->postJson('/api/v1/schedule/items', $payload)
            ->assertCreated()
            ->assertJsonPath('data.starts_on', '2026-10-25')
            ->assertJsonPath('data.category', 'other');
    });

    it('keeps explicit start and end dates', function () {
        $this->postJson('/api/v1/schedule/items', classPayload(['starts_on' => '2026-11-01', 'ends_on' => '2026-12-20']))
            ->assertCreated()
            ->assertJsonPath('data.starts_on', '2026-11-01')
            ->assertJsonPath('data.ends_on', '2026-12-20');
    });

    it('rejects start time not before end time', function (string $start, string $end) {
        $this->postJson('/api/v1/schedule/items', classPayload(['start_time' => $start, 'end_time' => $end]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_time');
    })->with([
        'equal' => ['09:00', '09:00'],
        'reversed' => ['10:00', '09:00'],
    ]);

    it('validates days, times, title, category and dates', function () {
        $this->postJson('/api/v1/schedule/items', classPayload(['days' => []]))->assertJsonValidationErrors('days');
        $this->postJson('/api/v1/schedule/items', classPayload(['days' => [7]]))->assertJsonValidationErrors('days.0');
        $this->postJson('/api/v1/schedule/items', classPayload(['days' => [1, 1]]))->assertJsonValidationErrors('days.0');
        $this->postJson('/api/v1/schedule/items', classPayload(['start_time' => '8am']))->assertJsonValidationErrors('start_time');
        $this->postJson('/api/v1/schedule/items', classPayload(['end_time' => '24:00']))->assertJsonValidationErrors('end_time');
        $this->postJson('/api/v1/schedule/items', classPayload(['title' => ' ']))->assertJsonValidationErrors('title');
        $this->postJson('/api/v1/schedule/items', classPayload(['category' => 'faith']))->assertJsonValidationErrors('category');
        $this->postJson('/api/v1/schedule/items', classPayload(['starts_on' => '2026-11-10', 'ends_on' => '2026-11-01']))->assertJsonValidationErrors('ends_on');
    });

    it('never takes user_id from the client', function () {
        $other = User::factory()->create();

        $id = $this->postJson('/api/v1/schedule/items', classPayload(['user_id' => $other->id]))->assertCreated()->json('data.id');

        expect(ScheduleItem::find($id)->user_id)->toBe($this->user->id);
    });

    it('enforces the 100 items per user limit', function () {
        ScheduleItem::factory()->count(ScheduleItem::MAX_PER_USER)->for($this->user)->create();

        $this->postJson('/api/v1/schedule/items', classPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    });
});

describe('list, edit, delete', function () {
    it('lists only my items, in start-time order', function () {
        $late = ScheduleItem::factory()->for($this->user)->create(['start_time' => '18:00', 'end_time' => '19:00']);
        $early = ScheduleItem::factory()->for($this->user)->create(['start_time' => '06:30', 'end_time' => '07:00']);
        ScheduleItem::factory()->create();

        $this->getJson('/api/v1/schedule/items')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $early->id)
            ->assertJsonPath('data.0.start_time', '06:30')
            ->assertJsonPath('data.1.id', $late->id);
    });

    it('edits all repeats, checking times against the stored values', function () {
        $item = ScheduleItem::factory()->for($this->user)->create(['start_time' => '08:00', 'end_time' => '09:30']);

        $this->patchJson("/api/v1/schedule/items/{$item->id}", ['title' => 'Physics', 'days' => [5, 6]])
            ->assertOk()
            ->assertJsonPath('data.title', 'Physics')
            ->assertJsonPath('data.days', [5, 6])
            ->assertJsonPath('data.start_time', '08:00');

        $this->patchJson("/api/v1/schedule/items/{$item->id}", ['start_time' => '10:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_time');

        $this->patchJson("/api/v1/schedule/items/{$item->id}", ['days' => []])->assertJsonValidationErrors('days');
        $this->patchJson("/api/v1/schedule/items/{$item->id}", ['ends_on' => '2026-09-01'])->assertJsonValidationErrors('ends_on');
        $this->patchJson("/api/v1/schedule/items/{$item->id}", ['ends_on' => null])->assertOk()->assertJsonPath('data.ends_on', null);
    });

    it('deletes all repeats', function () {
        $item = ScheduleItem::factory()->for($this->user)->create();

        $this->deleteJson("/api/v1/schedule/items/{$item->id}")->assertNoContent();

        $this->assertModelMissing($item);
    });

    it('returns 404 for an unknown or malformed id', function () {
        $this->patchJson('/api/v1/schedule/items/'.Str::uuid(), ['title' => 'x'])->assertNotFound();
        $this->deleteJson('/api/v1/schedule/items/not-a-uuid')->assertNotFound();
    });
});

describe('ownership', function () {
    it('forbids changing or deleting another user\'s item', function () {
        $theirs = ScheduleItem::factory()->create(['title' => 'Theirs']);

        $this->patchJson("/api/v1/schedule/items/{$theirs->id}", ['title' => 'Hacked'])->assertForbidden();
        $this->deleteJson("/api/v1/schedule/items/{$theirs->id}")->assertForbidden();

        expect($theirs->fresh()->title)->toBe('Theirs');
    });

    it('requires a token', function () {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/schedule/items')->assertUnauthorized();
    });

    it('deletes a user\'s schedule with the account', function () {
        $item = ScheduleItem::factory()->for($this->user)->create();

        $this->user->delete();

        $this->assertModelMissing($item);
    });
});
