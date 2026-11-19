<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user, 'sanctum');
});

describe('time format and week start', function () {
    it('defaults to 24h and Monday', function () {
        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.time_format', '24h')
            ->assertJsonPath('data.week_starts_on', 1);
    });

    it('defaults on a user registered through the API', function () {
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Sokha',
            'email' => 'sokha@example.com',
            'password' => 'correct-horse-battery',
            'timezone' => 'Asia/Phnom_Penh',
        ])->assertCreated()
            ->assertJsonPath('user.time_format', '24h')
            ->assertJsonPath('user.week_starts_on', 1);
    });

    it('saves both and returns them', function () {
        $this->patchJson('/api/v1/me', ['time_format' => '12h', 'week_starts_on' => 7])
            ->assertOk()
            ->assertJsonPath('data.time_format', '12h')
            ->assertJsonPath('data.week_starts_on', 7);

        expect($this->user->fresh()->only(['time_format', 'week_starts_on']))->toBe(['time_format' => '12h', 'week_starts_on' => 7]);
    });

    it('changes one field and leaves the other alone', function () {
        $this->patchJson('/api/v1/me', ['week_starts_on' => 6])->assertOk();
        $this->patchJson('/api/v1/me', ['time_format' => '12h'])
            ->assertOk()
            ->assertJsonPath('data.week_starts_on', 6);
    });

    it('accepts every day from 1 to 7', function (int $day) {
        $this->patchJson('/api/v1/me', ['week_starts_on' => $day])->assertOk()->assertJsonPath('data.week_starts_on', $day);
    })->with([1, 2, 3, 4, 5, 6, 7]);

    it('rejects bad values', function (array $payload, string $field) {
        $this->patchJson('/api/v1/me', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);

        expect($this->user->fresh()->only(['time_format', 'week_starts_on']))->toBe(['time_format' => '24h', 'week_starts_on' => 1]);
    })->with([
        'unknown format' => [['time_format' => 'x'], 'time_format'],
        'number as format' => [['time_format' => 12], 'time_format'],
        'null format' => [['time_format' => null], 'time_format'],
        'day 8' => [['week_starts_on' => 8], 'week_starts_on'],
        'day 0' => [['week_starts_on' => 0], 'week_starts_on'],
        'negative day' => [['week_starts_on' => -1], 'week_starts_on'],
        'day as text' => [['week_starts_on' => 'monday'], 'week_starts_on'],
        'fractional day' => [['week_starts_on' => 1.5], 'week_starts_on'],
    ]);
});

describe('time zone', function () {
    it('accepts a PHP identifier and rejects a made-up one', function () {
        $this->patchJson('/api/v1/me', ['timezone' => 'America/Los_Angeles'])->assertOk()->assertJsonPath('data.timezone', 'America/Los_Angeles');
        $this->patchJson('/api/v1/me', ['timezone' => 'Mars/Base'])->assertUnprocessable()->assertJsonValidationErrors('timezone');
        $this->patchJson('/api/v1/me', ['timezone' => 'GMT+7'])->assertUnprocessable()->assertJsonValidationErrors('timezone');
    });
});
