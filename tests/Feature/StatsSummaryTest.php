<?php

use App\Models\ScheduleItem;
use App\Models\Todo;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('requires authentication for analytics', function () {
    $this->getJson('/api/v1/stats/summary?from=2026-11-23&to=2026-11-29')->assertUnauthorized();
});

it('rejects invalid analytics ranges', function (string $query, string $field) {
    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson('/api/v1/stats/summary?'.$query)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    ['to=2026-11-29', 'from'],
    ['from=0000-01-01&to=0000-01-01', 'from'],
    ['from=2026-02-30&to=2026-03-01', 'from'],
    ['from=2026-11-29&to=2026-11-23', 'to'],
    ['from=2026-01-01&to=2026-04-03', 'to'],
]);

it('zero fills dates and buckets only owned live to-dos by due date and local completion date', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
    $this->actingAs($user, 'sanctum');

    $attributes = ['due_date' => '2026-11-23', 'done_at' => '2026-11-23 17:30:00'];
    Todo::factory()->for($user)->create($attributes);
    Todo::factory()->for($user)->create($attributes)->delete();
    Todo::factory()->create($attributes);

    DB::enableQueryLog();
    try {
        $this->getJson('/api/v1/stats/summary?from=2026-11-23&to=2026-11-29')
            ->assertOk()
            ->assertJsonCount(7, 'data.days')
            ->assertJsonPath('data.days.0.local_date', '2026-11-23')
            ->assertJsonPath('data.days.0.todos_due', 1)
            ->assertJsonPath('data.days.0.todos_done', 0)
            ->assertJsonPath('data.days.1.todos_done', 1)
            ->assertJsonPath('data.days.6.local_date', '2026-11-29')
            ->assertJsonPath('data.days.6.focus_ms', 0)
            ->assertJsonPath('data.totals.todos_due', 1)
            ->assertJsonPath('data.totals.todos_done', 1)
            ->assertJsonPath('data.by_mode', ['countdown' => 0, 'stopwatch' => 0]);

        expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(8);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

it('summarizes Focus and effective schedule occurrences using the shared fixture', function () {
    $this->travelTo(Carbon::parse('2026-12-01 12:00:00', 'UTC'));
    $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/stats-summary-cases.json')), true, flags: JSON_THROW_ON_ERROR);
    $fixture['expected']['opening_streak'] = 0;
    $fixture['expected']['opening_streak_may_be_longer'] = false;
    foreach ([1, 1, 0] as $index => $streak) {
        $fixture['expected']['days'][$index]['streak'] = $streak;
        $fixture['expected']['days'][$index]['streak_may_be_longer'] = false;
    }
    $user = User::factory()->create(['timezone' => $fixture['timezone']]);
    $other = User::factory()->create();
    $this->actingAs($user, 'sanctum');

    $item = ScheduleItem::factory()->for($user)->create($fixture['schedule']);
    ScheduleItem::factory()->for($other)->create($fixture['schedule']);
    $item->exceptions()->createMany($fixture['exceptions']);
    foreach ($fixture['checkins'] as $date) {
        $item->checkins()->create(['date' => $date, 'done_at' => now()]);
    }

    foreach ($fixture['sessions'] as $session) {
        $linked = $session['linked'];
        unset($session['linked']);
        $row = [
            ...$session,
            'end_reason' => 'completed',
            'started_at' => '2026-11-23 02:00:00',
            'ended_at' => '2026-11-23 02:30:00',
            'paused_ms' => 0,
            'segments' => '[]',
            'payload_hash' => str_repeat('a', 64),
        ];
        DB::table('focus_sessions')->insert([
            [...$row, 'id' => Str::uuid()->toString(), 'user_id' => $user->id, 'schedule_item_id' => $linked ? $item->id : null],
            [...$row, 'id' => Str::uuid()->toString(), 'user_id' => $other->id, 'schedule_item_id' => null],
        ]);
    }

    $this->getJson('/api/v1/stats/summary?'.http_build_query([
        'from' => $fixture['from'],
        'to' => $fixture['to'],
    ]))->assertOk()->assertExactJson(['data' => $fixture['expected']]);
});

it('accepts the inclusive 92 day limit', function () {
    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson('/api/v1/stats/summary?from=2026-01-01&to=2026-04-02')
        ->assertOk()
        ->assertJsonCount(92, 'data.days');
});

it('accepts the final representable date without generating dates beyond the requested range', function () {
    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson('/api/v1/stats/summary?from=9999-12-31&to=9999-12-31')
        ->assertOk()
        ->assertJsonCount(1, 'data.days')
        ->assertJsonPath('data.days.0.local_date', '9999-12-31');
});

it('accepts the first common-era date in a time zone ahead of UTC', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'Asia/Phnom_Penh']), 'sanctum')
        ->getJson('/api/v1/stats/summary?from=0001-01-01&to=0001-01-01')
        ->assertOk()
        ->assertJsonPath('data.days.0.local_date', '0001-01-01');
});

it('counts imported Focus on its local date and excludes it until restored after deletion', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'Asia/Phnom_Penh']), 'sanctum');
    $item = Str::uuid()->toString();
    $id = Str::uuid()->toString();
    $body = [
        'schedule_items' => [[
            'id' => $item, 'title' => 'Reading', 'start_time' => '00:00', 'end_time' => '01:00',
            'days' => [1], 'starts_on' => '2026-11-24',
        ]],
        'focus_sessions' => [[
            'id' => $id,
            'mode' => 'stopwatch',
            'end_reason' => 'completed',
            'started_at' => '2026-11-23T17:30:00.000Z',
            'ended_at' => '2026-11-23T17:31:30.000Z',
            'schedule_item_id' => $item,
            'segments' => [[
                'type' => 'focus',
                'started_at' => '2026-11-23T17:30:00.000Z',
                'ended_at' => '2026-11-23T17:31:30.000Z',
                'active_ms' => 90000,
            ]],
        ]],
    ];
    $summary = '/api/v1/stats/summary?from=2026-11-23&to=2026-11-24';
    $session = '/api/v1/focus-sessions/'.$id;

    $this->postJson('/api/v1/import', $body)->assertOk()->assertJsonPath('data.focus_sessions.created', 1);
    $this->getJson($summary)->assertOk()
        ->assertJsonPath('data.days.0.sessions', 0)
        ->assertJsonPath('data.days.1.local_date', '2026-11-24')
        ->assertJsonPath('data.days.1.planned_block_minutes', 60)
        ->assertJsonPath('data.days.1.focus_ms_linked', 90000)
        ->assertJsonPath('data.totals.sessions', 1);

    $this->deleteJson($session)->assertNoContent();
    $this->postJson('/api/v1/import', $body)->assertOk()->assertJsonPath('data.focus_sessions.skipped', 1);
    $this->getJson($summary)->assertOk()
        ->assertJsonPath('data.days.1.planned_block_minutes', 60)
        ->assertJsonPath('data.days.1.sessions', 0)
        ->assertJsonPath('data.totals.focus_ms', 0)
        ->assertJsonPath('data.by_mode.stopwatch', 0);

    $this->postJson($session.'/restore')->assertOk();
    $this->getJson($summary)->assertOk()
        ->assertJsonPath('data.days.1.focus_ms_linked', 90000)
        ->assertJsonPath('data.totals.sessions', 1)
        ->assertJsonPath('data.by_mode.stopwatch', 90000);
});

it('reports opening and daily streaks across local dates, skipped days, breaks and deleted completions', function () {
    $this->travelTo(Carbon::parse('2026-11-27 12:00:00', 'UTC'));
    $user = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
    Todo::factory()->for($user)->create(['due_date' => '2026-11-21', 'done_at' => null]);
    Todo::factory()->for($user)->create(['done_at' => '2026-11-21 18:00:00']);
    Todo::factory()->for($user)->create(['done_at' => '2026-11-22 18:00:00']);
    Todo::factory()->for($user)->create(['due_date' => '2026-11-25', 'done_at' => null]);
    Todo::factory()->for($user)->create(['done_at' => '2026-11-24 18:00:00'])->delete();
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/stats/summary?from=2026-11-23&to=2026-11-26')
        ->assertOk()
        ->assertJsonPath('data.opening_streak', 1)
        ->assertJsonPath('data.opening_streak_may_be_longer', false)
        ->assertJsonPath('data.days.0.streak', 2)
        ->assertJsonPath('data.days.1.streak', 2)
        ->assertJsonPath('data.days.2.streak', 0)
        ->assertJsonPath('data.days.2.streak_may_be_longer', false);
});

it('marks a streak as potentially longer when the 400 day window contains no break', function () {
    $this->travelTo(Carbon::parse('2026-11-27 12:00:00', 'UTC'));
    $user = User::factory()->create(['timezone' => 'UTC']);
    Todo::factory()->for($user)->create(['done_at' => '2026-11-23 12:00:00']);
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/stats/summary?from=2026-11-24&to=2026-11-24')
        ->assertOk()
        ->assertJsonPath('data.opening_streak', 1)
        ->assertJsonPath('data.opening_streak_may_be_longer', true)
        ->assertJsonPath('data.days.0.streak', 1)
        ->assertJsonPath('data.days.0.streak_may_be_longer', true);
});

it('limits each reported streak to 400 preceding days and detects a break at the boundary', function () {
    $this->travelTo(Carbon::parse('2026-11-27 12:00:00', 'UTC'));
    $user = User::factory()->create(['timezone' => 'UTC']);
    $target = CarbonImmutable::parse('2026-11-24');
    Todo::factory()->for($user)->create(['due_date' => $target->subDays(400)->toDateString(), 'done_at' => null]);
    Todo::factory()->for($user)->create(['done_at' => $target->subDays(401)->setTime(12, 0)]);
    Todo::factory()->for($user)->create(['done_at' => $target->subDay()->setTime(12, 0)]);
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/stats/summary?from=2026-11-24&to=2026-11-24')
        ->assertOk()
        ->assertJsonPath('data.days.0.streak', 1)
        ->assertJsonPath('data.days.0.streak_may_be_longer', false)
        ->assertJsonPath('data.opening_streak', 1)
        ->assertJsonPath('data.opening_streak_may_be_longer', false);
});

it('returns a complete zero when the window has no counted days', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/stats/summary?from=2026-01-01&to=2026-01-01')
        ->assertOk()
        ->assertJsonPath('data.opening_streak', 0)
        ->assertJsonPath('data.opening_streak_may_be_longer', false)
        ->assertJsonPath('data.days.0.streak', 0)
        ->assertJsonPath('data.days.0.streak_may_be_longer', false);
});

it('does not mark zero as longer when the only completion falls outside the historical window', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);
    $date = CarbonImmutable::parse('2026-01-01');
    Todo::factory()->for($user)->create(['done_at' => $date->subDays(401)->setTime(12, 0)]);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/stats/summary?from=2026-01-01&to=2026-01-01')
        ->assertOk()
        ->assertJsonPath('data.days.0.streak', 0)
        ->assertJsonPath('data.days.0.streak_may_be_longer', false)
        ->assertJsonPath('data.opening_streak', 1)
        ->assertJsonPath('data.opening_streak_may_be_longer', true);
});

it('marks a positive historical streak as a lower bound at the 400 day edge', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);
    $date = CarbonImmutable::parse('2026-01-01');
    Todo::factory()->for($user)->create(['done_at' => $date->subDays(400)->setTime(12, 0)]);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/stats/summary?from=2026-01-01&to=2026-01-01')
        ->assertOk()
        ->assertJsonPath('data.days.0.streak', 1)
        ->assertJsonPath('data.days.0.streak_may_be_longer', true);
});
