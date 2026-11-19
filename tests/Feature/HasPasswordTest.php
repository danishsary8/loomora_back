<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('is true for an email account', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.has_password', true);
});

it('is false for a Google-only account, without exposing the password or Google id', function () {
    $user = User::factory()->create(['password' => null, 'google_id' => 'g-123']);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.has_password', false)
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.google_id');
});

it('is in the user returned by sign-in and by PATCH /me', function () {
    User::factory()->create(['email' => 'sokha@example.com', 'password' => 'correct-horse-battery']);

    $login = $this->postJson('/api/v1/auth/login', ['email' => 'sokha@example.com', 'password' => 'correct-horse-battery'])
        ->assertOk()
        ->assertJsonPath('user.has_password', true);

    $this->app['auth']->forgetGuards();
    $this->withToken($login->json('token'))->patchJson('/api/v1/me', ['name' => 'Sokha K'])
        ->assertOk()
        ->assertJsonPath('data.has_password', true);
});
