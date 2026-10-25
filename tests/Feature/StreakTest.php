<?php

use App\Models\ScheduleItem;
use App\Models\Todo;
use App\Models\User;
use App\Services\StreakService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

// Today is Monday 2026-10-26 (10:00 in Phnom Penh, UTC+7). Weekday 0 = Monday.
beforeEach(function () {
    Carbon::setTestNow('2026-10-26 03:00:00');
    $this->user = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
    $this->actingAs($this->user, 'sanctum');
});

function doneOn(User $user, string $date, string $time = '12:00'): Todo
{
    return Todo::factory()->for($user)->create([
        'due_date' => $date,
        'done_at' => Carbon::parse("{$date} {$time}", $user->timezone)->utc(),
    ]);
}

function plannedTodo(User $user, string $date): Todo
{
    return Todo::factory()->for($user)->due($date)->create();
}

function streak(User $user, string $today = '2026-10-26'): array
{
    return app(StreakService::class)->recompute($user, $today);
}

describe('counting', function () {
    it('is zero with no data', function () {
        expect(streak($this->user))->toBe(['current_streak' => 0, 'longest_streak' => 0, 'today_counted' => false]);
    });

    it('counts today as soon as one thing is checked off', function () {
        doneOn($this->user, '2026-10-26');

        expect(streak($this->user))->toBe(['current_streak' => 1, 'longest_streak' => 1, 'today_counted' => true]);
    });

    it('counts consecutive days and does not judge today', function () {
        doneOn($this->user, '2026-10-23');
        doneOn($this->user, '2026-10-24');
        doneOn($this->user, '2026-10-25');
        plannedTodo($this->user, '2026-10-26'); // today is planned but not done yet

        expect(streak($this->user))->toMatchArray(['current_streak' => 3, 'today_counted' => false]);

        doneOn($this->user, '2026-10-26');
        expect(streak($this->user))->toMatchArray(['current_streak' => 4, 'today_counted' => true]);
    });

    it('counts an undated to-do checked off that day', function () {
        Todo::factory()->for($this->user)->create(['due_date' => null, 'done_at' => Carbon::parse('2026-10-25 09:00', 'Asia/Phnom_Penh')->utc()]);

        expect(streak($this->user)['current_streak'])->toBe(1);
    });

    it('counts a schedule check-in on its occurrence date', function () {
        $item = ScheduleItem::factory()->for($this->user)->create(['days' => [0, 1, 2, 3, 4, 5, 6], 'starts_on' => '2026-10-01']);
        $item->checkins()->create(['date' => '2026-10-25', 'done_at' => now()]);
        $item->checkins()->create(['date' => '2026-10-24', 'done_at' => now()]);

        expect(streak($this->user)['current_streak'])->toBe(2);
    });
});

describe('breaking and skipping', function () {
    it('a planned day with no check-off breaks the streak', function () {
        doneOn($this->user, '2026-10-23');
        plannedTodo($this->user, '2026-10-24');
        doneOn($this->user, '2026-10-25');

        expect(streak($this->user)['current_streak'])->toBe(1); // only 10-25, the break at 10-24 stops it
    });

    it('a missed planned day yesterday gives zero until today is checked off', function () {
        doneOn($this->user, '2026-10-23');
        plannedTodo($this->user, '2026-10-25');

        expect(streak($this->user)['current_streak'])->toBe(0);

        doneOn($this->user, '2026-10-26');
        expect(streak($this->user)['current_streak'])->toBe(1);
    });

    it('skips days with nothing planned', function () {
        doneOn($this->user, '2026-10-22');
        // 10-23, 10-24, 10-25: nothing planned, nothing done
        expect(streak($this->user)['current_streak'])->toBe(1);

        doneOn($this->user, '2026-10-26');
        expect(streak($this->user)['current_streak'])->toBe(2);
    });

    it('a schedule occurrence without a check-in is a planned day', function () {
        ScheduleItem::factory()->for($this->user)->create(['days' => [5], 'starts_on' => '2026-10-01']); // Saturdays
        doneOn($this->user, '2026-10-23');

        expect(streak($this->user)['current_streak'])->toBe(0); // Sat 10-24 planned, not checked in
    });

    it('a skipped schedule day is not planned', function () {
        $item = ScheduleItem::factory()->for($this->user)->create(['days' => [5], 'starts_on' => '2026-10-01']);
        $item->exceptions()->create(['date' => '2026-10-24', 'type' => 'skip']);
        doneOn($this->user, '2026-10-23');

        expect(streak($this->user)['current_streak'])->toBe(1);
    });

    it('a day done and also planned does not break', function () {
        doneOn($this->user, '2026-10-24');
        plannedTodo($this->user, '2026-10-24');
        doneOn($this->user, '2026-10-25');

        expect(streak($this->user)['current_streak'])->toBe(2);
    });

    it('ignores other users\' data', function () {
        $other = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
        doneOn($other, '2026-10-25');
        plannedTodo($other, '2026-10-24');

        expect(streak($this->user)['current_streak'])->toBe(0);
    });
});

describe('time zones', function () {
    it('uses the user\'s local day for done_at', function () {
        // 2026-10-25 17:30 UTC is 00:30 on 10-26 in Phnom Penh: it counts for today, not yesterday.
        Todo::factory()->for($this->user)->create(['done_at' => '2026-10-25 17:30:00']);

        expect(streak($this->user))->toMatchArray(['current_streak' => 1, 'today_counted' => true]);
    });

    it('does not count a late local evening on the wrong day', function () {
        // 2026-10-25 16:30 UTC is 23:30 on 10-25 locally.
        Todo::factory()->for($this->user)->create(['done_at' => '2026-10-25 16:30:00']);

        expect(streak($this->user))->toMatchArray(['current_streak' => 1, 'today_counted' => false]);
    });

    it('works west of UTC too', function () {
        $la = User::factory()->create(['timezone' => 'America/Los_Angeles']);
        // 2026-10-26 03:00 UTC is still Sunday 10-25 in Los Angeles (UTC-7).
        Todo::factory()->for($la)->create(['done_at' => '2026-10-26 03:00:00']);

        expect(streak($la, '2026-10-25'))->toMatchArray(['current_streak' => 1, 'today_counted' => true]);
    });
});

describe('stored values', function () {
    it('stores current, longest and last date', function () {
        doneOn($this->user, '2026-10-24');
        doneOn($this->user, '2026-10-25');

        streak($this->user);
        $this->user->refresh();

        expect($this->user->current_streak)->toBe(2)
            ->and($this->user->longest_streak)->toBe(2)
            ->and($this->user->last_streak_date->toDateString())->toBe('2026-10-25');
    });

    it('keeps the longest streak after the current one breaks', function () {
        $this->user->forceFill(['longest_streak' => 9])->save();
        plannedTodo($this->user, '2026-10-25');

        $result = streak($this->user);

        expect($result)->toMatchArray(['current_streak' => 0, 'longest_streak' => 9]);
        expect($this->user->refresh()->last_streak_date)->toBeNull();
    });

    it('raises longest when the current streak passes it', function () {
        $this->user->forceFill(['longest_streak' => 1])->save();
        doneOn($this->user, '2026-10-24');
        doneOn($this->user, '2026-10-25');

        expect(streak($this->user)['longest_streak'])->toBe(2);
    });
});

describe('endpoints', function () {
    it('GET /streak recomputes and returns the values', function () {
        doneOn($this->user, '2026-10-25');

        $this->getJson('/api/v1/streak?date=2026-10-26')
            ->assertOk()
            ->assertJsonPath('data.current_streak', 1)
            ->assertJsonPath('data.longest_streak', 1)
            ->assertJsonPath('data.today_counted', false);

        expect($this->user->refresh()->current_streak)->toBe(1);
    });

    it('GET /streak needs a date within a day of the user\'s today, and a token', function () {
        $this->getJson('/api/v1/streak')->assertUnprocessable();
        $this->getJson('/api/v1/streak?date=2026-10-20')->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/streak?date=2026-10-26')->assertUnauthorized();
    });

    it('checking off and undoing a to-do updates the stored streak', function () {
        $todo = plannedTodo($this->user, '2026-10-26');

        $this->postJson("/api/v1/todos/{$todo->id}/done")->assertOk();
        expect($this->user->refresh())->current_streak->toBe(1)->longest_streak->toBe(1);

        $this->deleteJson("/api/v1/todos/{$todo->id}/done")->assertOk();
        expect($this->user->refresh())->current_streak->toBe(0)->longest_streak->toBe(1);
    });

    it('checking in and undoing a schedule occurrence updates the stored streak', function () {
        $item = ScheduleItem::factory()->for($this->user)->create(['days' => [0], 'starts_on' => '2026-10-01']);
        $url = "/api/v1/schedule/items/{$item->id}/checkins/2026-10-26";

        $this->postJson($url)->assertCreated();
        expect($this->user->refresh()->current_streak)->toBe(1);

        $this->deleteJson($url)->assertNoContent();
        expect($this->user->refresh()->current_streak)->toBe(0);
    });

    it('shows the stored streak on /me', function () {
        doneOn($this->user, '2026-10-26');
        streak($this->user);

        $this->getJson('/api/v1/me')->assertJsonPath('data.current_streak', 1);
    });
});
