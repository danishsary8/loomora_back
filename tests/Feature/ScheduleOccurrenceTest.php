<?php

use App\Models\ScheduleCheckin;
use App\Models\ScheduleException;
use App\Models\ScheduleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

// 2026-10-26 is a Monday (weekday 0), 2026-10-27 Tuesday, 2026-10-28 Wednesday.
beforeEach(function () {
    Carbon::setTestNow('2026-10-26 03:00:00'); // 10:00 Monday in Phnom Penh
    $this->user = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
    $this->actingAs($this->user, 'sanctum');
});

function makeItem(User $user, array $attrs = []): ScheduleItem
{
    return ScheduleItem::factory()->for($user)->create(array_merge([
        'title' => 'Math',
        'start_time' => '08:00',
        'end_time' => '09:30',
        'days' => [0, 2, 4],
        'starts_on' => '2026-10-01',
    ], $attrs));
}

describe('day occurrences', function () {
    it('returns an item only on its weekdays', function () {
        makeItem($this->user);

        $this->getJson('/api/v1/schedule/day?date=2026-10-26')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Math')
            ->assertJsonPath('data.0.start_time', '08:00')
            ->assertJsonPath('data.0.end_time', '09:30')
            ->assertJsonPath('data.0.done', false)
            ->assertJsonPath('data.0.overridden', false);

        $this->getJson('/api/v1/schedule/day?date=2026-10-27')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/schedule/day?date=2026-10-28')->assertOk()->assertJsonCount(1, 'data');
    });

    it('respects starts_on and ends_on, both inclusive', function () {
        makeItem($this->user, ['days' => [0, 1, 2, 3, 4, 5, 6], 'starts_on' => '2026-10-26', 'ends_on' => '2026-10-28']);

        foreach (['2026-10-25' => 0, '2026-10-26' => 1, '2026-10-28' => 1, '2026-10-29' => 0] as $date => $count) {
            $this->getJson("/api/v1/schedule/day?date={$date}")->assertJsonCount($count, 'data');
        }
    });

    it('orders occurrences by start time', function () {
        makeItem($this->user, ['title' => 'Late', 'start_time' => '14:00', 'end_time' => '15:00']);
        makeItem($this->user, ['title' => 'Early', 'start_time' => '07:00', 'end_time' => '08:00']);

        $this->getJson('/api/v1/schedule/day?date=2026-10-26')
            ->assertJsonPath('data.0.title', 'Early')
            ->assertJsonPath('data.1.title', 'Late');
    });

    it('never shows another user\'s items', function () {
        makeItem(User::factory()->create());

        $this->getJson('/api/v1/schedule/day?date=2026-10-26')->assertJsonCount(0, 'data');
    });

    it('requires a valid date and a token', function () {
        $this->getJson('/api/v1/schedule/day')->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->getJson('/api/v1/schedule/day?date=26-10-2026')->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/schedule/day?date=2026-10-26')->assertUnauthorized();
    });
});

describe('week', function () {
    it('returns seven days with their occurrences', function () {
        makeItem($this->user);

        $response = $this->getJson('/api/v1/schedule/week?start=2026-10-26')->assertOk();

        expect($response->json('data'))->toHaveCount(7)
            ->and(array_column($response->json('data'), 'date'))->toBe([
                '2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30', '2026-10-31', '2026-11-01',
            ])
            ->and(array_map(fn ($d) => count($d['occurrences']), $response->json('data')))->toBe([1, 0, 1, 0, 1, 0, 0]);
    });

    it('applies exceptions and check-ins per day', function () {
        $item = makeItem($this->user);
        $item->exceptions()->create(['date' => '2026-10-28', 'type' => 'skip']);
        $item->checkins()->create(['date' => '2026-10-26', 'done_at' => now()]);

        $days = $this->getJson('/api/v1/schedule/week?start=2026-10-26')->json('data');

        expect($days[0]['occurrences'][0]['done'])->toBeTrue()
            ->and($days[2]['occurrences'])->toBe([])
            ->and($days[4]['occurrences'][0]['done'])->toBeFalse();
    });
});

describe('exceptions', function () {
    it('skips a single day and restores it', function () {
        $item = makeItem($this->user);

        $this->putJson("/api/v1/schedule/items/{$item->id}/exceptions/2026-10-26", ['type' => 'skip'])
            ->assertOk()->assertJsonPath('data.type', 'skip');

        $this->getJson('/api/v1/schedule/day?date=2026-10-26')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/schedule/day?date=2026-10-28')->assertJsonCount(1, 'data');

        $this->deleteJson("/api/v1/schedule/items/{$item->id}/exceptions/2026-10-26")->assertNoContent();
        $this->getJson('/api/v1/schedule/day?date=2026-10-26')->assertJsonCount(1, 'data');
        $this->deleteJson("/api/v1/schedule/items/{$item->id}/exceptions/2026-10-26")->assertNoContent();
    });

    it('overrides title and times for one day only', function () {
        $item = makeItem($this->user);

        $this->putJson("/api/v1/schedule/items/{$item->id}/exceptions/2026-10-26", [
            'type' => 'override', 'title' => 'Exam', 'start_time' => '10:00', 'end_time' => '12:00',
        ])->assertOk();

        $this->getJson('/api/v1/schedule/day?date=2026-10-26')
            ->assertJsonPath('data.0.title', 'Exam')
            ->assertJsonPath('data.0.start_time', '10:00')
            ->assertJsonPath('data.0.end_time', '12:00')
            ->assertJsonPath('data.0.overridden', true);
        $this->getJson('/api/v1/schedule/day?date=2026-10-28')
            ->assertJsonPath('data.0.title', 'Math')
            ->assertJsonPath('data.0.start_time', '08:00');
    });

    it('overrides only the title and keeps the item times', function () {
        $item = makeItem($this->user);

        $this->putJson("/api/v1/schedule/items/{$item->id}/exceptions/2026-10-26", ['type' => 'override', 'title' => 'Quiz'])->assertOk();

        $this->getJson('/api/v1/schedule/day?date=2026-10-26')
            ->assertJsonPath('data.0.title', 'Quiz')
            ->assertJsonPath('data.0.start_time', '08:00');
    });

    it('replaces an earlier exception for the same day', function () {
        $item = makeItem($this->user);
        $url = "/api/v1/schedule/items/{$item->id}/exceptions/2026-10-26";

        $this->putJson($url, ['type' => 'override', 'title' => 'Exam'])->assertOk();
        $this->putJson($url, ['type' => 'skip', 'title' => 'ignored'])->assertOk()->assertJsonPath('data.title', null);

        expect(ScheduleException::count())->toBe(1);
    });

    it('rejects bad exceptions', function () {
        $item = makeItem($this->user);
        $base = "/api/v1/schedule/items/{$item->id}/exceptions";

        $this->putJson("{$base}/2026-10-26", ['type' => 'override'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->putJson("{$base}/2026-10-26", ['type' => 'override', 'start_time' => '10:00', 'end_time' => '09:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('end_time');
        $this->putJson("{$base}/2026-10-26", ['type' => 'override', 'start_time' => '09:45'])
            ->assertUnprocessable()->assertJsonValidationErrors('end_time'); // item ends 09:30
        $this->putJson("{$base}/2026-10-26", ['type' => 'nope'])->assertUnprocessable();
        $this->putJson("{$base}/2026-10-27", ['type' => 'skip'])->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->putJson("{$base}/2026-02-30", ['type' => 'skip'])->assertUnprocessable()->assertJsonValidationErrors('date');

        expect(ScheduleException::count())->toBe(0);
    });
});

describe('check-ins', function () {
    it('toggles an occurrence done and back', function () {
        $item = makeItem($this->user);
        $url = "/api/v1/schedule/items/{$item->id}/checkins/2026-10-26";

        $this->postJson($url)->assertCreated()->assertJsonPath('data.done', true);
        $this->getJson('/api/v1/schedule/day?date=2026-10-26')->assertJsonPath('data.0.done', true);
        $this->getJson('/api/v1/schedule/day?date=2026-10-28')->assertJsonPath('data.0.done', false);

        $this->deleteJson($url)->assertNoContent();
        $this->getJson('/api/v1/schedule/day?date=2026-10-26')->assertJsonPath('data.0.done', false);
        $this->deleteJson($url)->assertNoContent();
    });

    it('is idempotent', function () {
        $item = makeItem($this->user);
        $url = "/api/v1/schedule/items/{$item->id}/checkins/2026-10-26";

        $this->postJson($url)->assertCreated();
        $this->postJson($url)->assertOk();

        expect(ScheduleCheckin::count())->toBe(1);
    });

    it('refuses dates where the item does not occur or is skipped', function () {
        $item = makeItem($this->user);
        $item->exceptions()->create(['date' => '2026-10-26', 'type' => 'skip']);

        $this->postJson("/api/v1/schedule/items/{$item->id}/checkins/2026-10-26")->assertUnprocessable();
        $this->postJson("/api/v1/schedule/items/{$item->id}/checkins/2026-10-27")->assertUnprocessable();
        $this->postJson("/api/v1/schedule/items/{$item->id}/checkins/2026-02-30")->assertUnprocessable();

        expect(ScheduleCheckin::count())->toBe(0);
    });
});

describe('now and next', function () {
    beforeEach(function () {
        makeItem($this->user, ['title' => 'Math', 'start_time' => '08:00', 'end_time' => '09:30']);
        makeItem($this->user, ['title' => 'Gym', 'start_time' => '17:00', 'end_time' => '18:00']);
        makeItem($this->user, ['title' => 'Read', 'start_time' => '20:00', 'end_time' => '21:00']);
    });

    it('finds the current and the next occurrence', function () {
        $this->getJson('/api/v1/schedule/now?date=2026-10-26&time=08:30')
            ->assertOk()
            ->assertJsonPath('now.title', 'Math')
            ->assertJsonPath('next.title', 'Gym');
    });

    it('treats start as inclusive and end as exclusive', function () {
        $this->getJson('/api/v1/schedule/now?date=2026-10-26&time=08:00')->assertJsonPath('now.title', 'Math');
        $this->getJson('/api/v1/schedule/now?date=2026-10-26&time=09:30')
            ->assertJsonPath('now', null)
            ->assertJsonPath('next.title', 'Gym');
    });

    it('has no now between items (Free)', function () {
        $this->getJson('/api/v1/schedule/now?date=2026-10-26&time=12:00')
            ->assertJsonPath('now', null)
            ->assertJsonPath('next.title', 'Gym');
    });

    it('has no next when nothing more is planned today', function () {
        $this->getJson('/api/v1/schedule/now?date=2026-10-26&time=20:30')
            ->assertJsonPath('now.title', 'Read')
            ->assertJsonPath('next', null);
        $this->getJson('/api/v1/schedule/now?date=2026-10-26&time=22:00')
            ->assertJsonPath('now', null)
            ->assertJsonPath('next', null);
    });

    it('ignores skipped occurrences and uses overrides', function () {
        $math = ScheduleItem::where('title', 'Math')->first();
        $math->exceptions()->create(['date' => '2026-10-26', 'type' => 'skip']);
        ScheduleItem::where('title', 'Gym')->first()->exceptions()->create([
            'date' => '2026-10-26', 'type' => 'override', 'start_time' => '08:10', 'end_time' => '08:50',
        ]);

        $this->getJson('/api/v1/schedule/now?date=2026-10-26&time=08:30')
            ->assertJsonPath('now.title', 'Gym')
            ->assertJsonPath('next.title', 'Read');
    });

    it('returns nothing on a day without items', function () {
        $this->getJson('/api/v1/schedule/now?date=2026-10-27&time=08:30')
            ->assertJsonPath('now', null)->assertJsonPath('next', null);
    });

    it('checks the date against the user time zone', function () {
        // 03:00 UTC Monday is 10:00 Monday in Phnom Penh. Today +-1 day passes, 2 days away fails.
        $this->getJson('/api/v1/schedule/now?date=2026-10-25&time=10:00')->assertOk();
        $this->getJson('/api/v1/schedule/now?date=2026-10-27&time=10:00')->assertOk();
        $this->getJson('/api/v1/schedule/now?date=2026-10-28&time=10:00')->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->getJson('/api/v1/schedule/now?date=2026-10-26')->assertUnprocessable()->assertJsonValidationErrors('time');
    });

    it('uses the user\'s local date near midnight UTC', function () {
        // 20:00 UTC Sunday is already 03:00 Monday in Phnom Penh.
        Carbon::setTestNow('2026-10-25 20:00:00');

        $this->getJson('/api/v1/schedule/now?date=2026-10-26&time=03:00')->assertOk()->assertJsonPath('next.title', 'Math');
        $this->getJson('/api/v1/schedule/now?date=2026-10-24&time=03:00')->assertUnprocessable();
    });
});

describe('copy day', function () {
    it('adds the target weekdays to items that run on the source weekday', function () {
        $monday = makeItem($this->user, ['title' => 'Math', 'days' => [0]]);
        $other = makeItem($this->user, ['title' => 'Art', 'days' => [3]]);

        $this->postJson('/api/v1/schedule/copy-day', ['from_weekday' => 0, 'to_weekdays' => [1, 2]])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.days', [0, 1, 2]);

        expect($monday->refresh()->days)->toBe([0, 1, 2])->and($other->refresh()->days)->toBe([3]);
        $this->getJson('/api/v1/schedule/day?date=2026-10-27')->assertJsonCount(1, 'data');
    });

    it('does not duplicate days and ignores other users', function () {
        makeItem($this->user, ['days' => [0, 1]]);
        $foreign = makeItem(User::factory()->create(), ['days' => [0]]);

        $this->postJson('/api/v1/schedule/copy-day', ['from_weekday' => 0, 'to_weekdays' => [1, 4]])
            ->assertOk()->assertJsonPath('data.0.days', [0, 1, 4]);

        expect($foreign->refresh()->days)->toBe([0]);
    });

    it('validates the payload', function () {
        $this->postJson('/api/v1/schedule/copy-day', [])->assertUnprocessable();
        $this->postJson('/api/v1/schedule/copy-day', ['from_weekday' => 0, 'to_weekdays' => [0]])->assertUnprocessable();
        $this->postJson('/api/v1/schedule/copy-day', ['from_weekday' => 7, 'to_weekdays' => [1]])->assertUnprocessable();
        $this->postJson('/api/v1/schedule/copy-day', ['from_weekday' => 0, 'to_weekdays' => []])->assertUnprocessable();
    });
});

describe('ownership and cascade', function () {
    it('denies exceptions and check-ins on another user\'s item', function () {
        $item = makeItem(User::factory()->create());

        $this->putJson("/api/v1/schedule/items/{$item->id}/exceptions/2026-10-26", ['type' => 'skip'])->assertForbidden();
        $this->deleteJson("/api/v1/schedule/items/{$item->id}/exceptions/2026-10-26")->assertForbidden();
        $this->postJson("/api/v1/schedule/items/{$item->id}/checkins/2026-10-26")->assertForbidden();
        $this->deleteJson("/api/v1/schedule/items/{$item->id}/checkins/2026-10-26")->assertForbidden();

        expect(ScheduleException::count())->toBe(0)->and(ScheduleCheckin::count())->toBe(0);
    });

    it('deleting an item removes its exceptions and check-ins', function () {
        $item = makeItem($this->user);
        $item->exceptions()->create(['date' => '2026-10-26', 'type' => 'skip']);
        $item->checkins()->create(['date' => '2026-10-28', 'done_at' => now()]);

        $this->deleteJson("/api/v1/schedule/items/{$item->id}")->assertNoContent();

        expect(ScheduleException::count())->toBe(0)->and(ScheduleCheckin::count())->toBe(0);
    });
});
