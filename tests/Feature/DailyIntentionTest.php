<?php

use App\Models\DailyIntention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    // 10:00 on 2026-10-24 in Phnom Penh (UTC+7).
    Carbon::setTestNow('2026-10-24 03:00:00');
    $this->user = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
    $this->actingAs($this->user, 'sanctum');
});

describe('reading and writing', function () {
    it('returns null text when nothing is set', function () {
        $this->getJson('/api/v1/days/2026-10-24/intention')
            ->assertOk()
            ->assertExactJson(['data' => ['date' => '2026-10-24', 'text' => null]]);
    });

    it('saves with put and reads it back', function () {
        $this->putJson('/api/v1/days/2026-10-24/intention', ['text' => 'Finish the essay'])
            ->assertOk()
            ->assertExactJson(['data' => ['date' => '2026-10-24', 'text' => 'Finish the essay']]);

        $this->getJson('/api/v1/days/2026-10-24/intention')->assertOk()->assertJsonPath('data.text', 'Finish the essay');
    });

    it('updates the same row on a second put', function () {
        $this->putJson('/api/v1/days/2026-10-24/intention', ['text' => 'First'])->assertOk();
        $this->putJson('/api/v1/days/2026-10-24/intention', ['text' => 'Second'])->assertOk()->assertJsonPath('data.text', 'Second');

        expect(DailyIntention::count())->toBe(1)
            ->and(DailyIntention::first()->text)->toBe('Second');
    });

    it('keeps each day separate', function () {
        $this->putJson('/api/v1/days/2026-10-24/intention', ['text' => 'Today'])->assertOk();
        $this->putJson('/api/v1/days/2026-10-25/intention', ['text' => 'Tomorrow'])->assertOk();

        $this->getJson('/api/v1/days/2026-10-24/intention')->assertJsonPath('data.text', 'Today');
        $this->getJson('/api/v1/days/2026-10-25/intention')->assertJsonPath('data.text', 'Tomorrow');
    });

    it('deletes the row when the text is empty or blank', function (mixed $empty) {
        $this->putJson('/api/v1/days/2026-10-24/intention', ['text' => 'Something'])->assertOk();

        $this->putJson('/api/v1/days/2026-10-24/intention', ['text' => $empty])
            ->assertOk()
            ->assertJsonPath('data.text', null);

        expect(DailyIntention::count())->toBe(0);
        $this->getJson('/api/v1/days/2026-10-24/intention')->assertJsonPath('data.text', null);
    })->with(['', '   ', null]);

    it('accepts an empty put when nothing exists', function () {
        $this->putJson('/api/v1/days/2026-10-24/intention', ['text' => ''])->assertOk()->assertJsonPath('data.text', null);
    });

    it('trims the text and allows exactly 200 characters', function () {
        $this->putJson('/api/v1/days/2026-10-24/intention', ['text' => '  Read  '])->assertOk()->assertJsonPath('data.text', 'Read');
        $this->putJson('/api/v1/days/2026-10-24/intention', ['text' => str_repeat('a', 200)])->assertOk();
    });

    it('rejects text over 200 characters, a missing text and a non-string', function (array $payload) {
        $this->putJson('/api/v1/days/2026-10-24/intention', $payload)->assertUnprocessable()->assertJsonValidationErrors('text');
    })->with([
        'too long' => [['text' => str_repeat('a', 201)]],
        'missing' => [[]],
        'array' => [['text' => ['x']]],
    ]);
});

describe('the date', function () {
    it('allows days up to 366 away in either direction', function () {
        $this->getJson('/api/v1/days/2027-10-25/intention')->assertOk();
        $this->getJson('/api/v1/days/2025-10-23/intention')->assertOk();
        $this->putJson('/api/v1/days/2027-10-25/intention', ['text' => 'Far ahead'])->assertOk();
    });

    it('rejects a bad or too distant date with 422', function (string $date) {
        $this->getJson("/api/v1/days/{$date}/intention")->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->putJson("/api/v1/days/{$date}/intention", ['text' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('date');

        expect(DailyIntention::count())->toBe(0);
    })->with(['2027-10-26', '2025-10-22', 'tomorrow', '2026-02-30', '24-10-2026', '2026-13-01']);

    it('measures from the user\'s own today, not the UTC date', function () {
        // 2026-10-24 17:30 UTC is already 2026-10-25 00:30 in Phnom Penh.
        Carbon::setTestNow('2026-10-24 17:30:00');

        $this->getJson('/api/v1/days/2027-10-26/intention')->assertOk();
        $this->getJson('/api/v1/days/2027-10-27/intention')->assertUnprocessable();
    });
});

describe('privacy', function () {
    it('never shows another user\'s intention', function () {
        DailyIntention::create(['user_id' => User::factory()->create()->id, 'local_date' => '2026-10-24', 'text' => 'Secret']);

        $this->getJson('/api/v1/days/2026-10-24/intention')->assertOk()->assertJsonPath('data.text', null);
    });

    it('does not touch another user\'s intention on put or delete', function () {
        $other = User::factory()->create();
        DailyIntention::create(['user_id' => $other->id, 'local_date' => '2026-10-24', 'text' => 'Theirs']);

        $this->putJson('/api/v1/days/2026-10-24/intention', ['text' => 'Mine'])->assertOk();
        $this->putJson('/api/v1/days/2026-10-24/intention', ['text' => ''])->assertOk();

        expect(DailyIntention::where('user_id', $other->id)->value('text'))->toBe('Theirs');
    });

    it('requires a signed-in user', function () {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/days/2026-10-24/intention')->assertUnauthorized();
        $this->putJson('/api/v1/days/2026-10-24/intention', ['text' => 'x'])->assertUnauthorized();
    });

    it('is removed with the account', function () {
        $this->putJson('/api/v1/days/2026-10-24/intention', ['text' => 'Gone soon'])->assertOk();

        $this->user->delete();

        expect(DailyIntention::count())->toBe(0);
    });
});

describe('import', function () {
    it('creates one row per day and leaves one row when sent twice', function () {
        $payload = ['daily_intentions' => [
            ['date' => '2026-10-20', 'text' => 'Old goal'],
            ['date' => '2026-10-24', 'text' => 'Today goal'],
        ]];

        $this->postJson('/api/v1/import', $payload)->assertOk()->assertJsonPath('data.daily_intentions', ['created' => 2, 'skipped' => 0]);
        $this->postJson('/api/v1/import', $payload)->assertOk()->assertJsonPath('data.daily_intentions', ['created' => 0, 'skipped' => 2]);

        expect(DailyIntention::count())->toBe(2);
        $this->getJson('/api/v1/days/2026-10-24/intention')->assertJsonPath('data.text', 'Today goal');
    });

    it('keeps the server copy when the day already has an intention', function () {
        $this->putJson('/api/v1/days/2026-10-24/intention', ['text' => 'Server'])->assertOk();

        $this->postJson('/api/v1/import', ['daily_intentions' => [['date' => '2026-10-24', 'text' => 'Guest']]])
            ->assertOk()
            ->assertJsonPath('data.daily_intentions', ['created' => 0, 'skipped' => 1]);

        $this->getJson('/api/v1/days/2026-10-24/intention')->assertJsonPath('data.text', 'Server');
    });

    it('reports zero when the key is missing', function () {
        $this->postJson('/api/v1/import', [])->assertOk()->assertJsonPath('data.daily_intentions', ['created' => 0, 'skipped' => 0]);
    });

    it('rejects bad rows and writes nothing', function (array $row, string $key) {
        $this->postJson('/api/v1/import', ['todos' => [['id' => fake()->uuid(), 'title' => 'x']], 'daily_intentions' => [$row]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($key);

        expect(DailyIntention::count())->toBe(0);
    })->with([
        'bad date' => [['date' => 'yesterday', 'text' => 'x'], 'daily_intentions.0.date'],
        'no text' => [['date' => '2026-10-24'], 'daily_intentions.0.text'],
        'empty text' => [['date' => '2026-10-24', 'text' => ''], 'daily_intentions.0.text'],
        'long text' => [['date' => '2026-10-24', 'text' => 'abcdefghij abcdefghij abcdefghij abcdefghij abcdefghij abcdefghij abcdefghij abcdefghij abcdefghij abcdefghij abcdefghij abcdefghij abcdefghij abcdefghij abcdefghij abcdefghij abcdefghij abcdefghij abcdefghij abcdefghij abcdefghij'], 'daily_intentions.0.text'],
    ]);

    it('rejects two rows for the same day', function () {
        $this->postJson('/api/v1/import', ['daily_intentions' => [
            ['date' => '2026-10-24', 'text' => 'One'],
            ['date' => '2026-10-24', 'text' => 'Two'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('daily_intentions.0.date');
    });
});
