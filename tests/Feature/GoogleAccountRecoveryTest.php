<?php

use App\Mail\ResetPasswordMail;
use App\Models\ScheduleItem;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
});

/** @return array{email: string, token: string} */
function requestGoogleAccountRecovery(TestCase $test, User $user): array
{
    $previous = Mail::sent(ResetPasswordMail::class)->count();
    $test->postJson('/api/v1/auth/password/forgot', ['email' => ' '.strtoupper($user->email).' '])
        ->assertOk()->assertExactJson(['message' => __('auth_flow.reset_link_sent')]);

    $sent = Mail::sent(ResetPasswordMail::class);
    expect($sent->count())->toBe($previous + 1);
    $mail = $sent->last();
    expect($mail)->toBeInstanceOf(ResetPasswordMail::class);
    expect(count($mail->to) === 1 && $mail->hasTo($user->email) && $mail->cc === [] && $mail->bcc === [])->toBeTrue()
        ->and(str_starts_with($mail->url, config('app.frontend_url').'/reset-password?'))->toBeTrue();
    parse_str(parse_url($mail->url, PHP_URL_QUERY), $params);
    expect($params['email'])->toBe($user->email)
        ->and(isset($params['token']) && is_string($params['token']) && $params['token'] !== '')->toBeTrue();

    return $params;
}

/** @return list<Model> */
function seedRecoveryAccountData(User $user): array
{
    $item = ScheduleItem::factory()->for($user)->create();
    $parent = Todo::factory()->for($user)->create();
    $subtask = Todo::factory()->for($user)->create(['parent_id' => $parent->id]);
    $subtask->delete();

    DB::table('sessions')->insert(['id' => $user->id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => 0]);

    return [
        $user,
        $user->feedback()->create(['message' => 'Account feedback']),
        $user->dailyIntentions()->create(['local_date' => '2026-11-20', 'text' => 'My intention']),
        $item,
        $item->exceptions()->create(['date' => '2026-11-20', 'type' => 'skip']),
        $item->checkins()->create(['date' => '2026-11-19', 'done_at' => now()]),
        $parent,
        $subtask,
    ];
}

it('recovers the same Google account and deletes it only after a new sign-in and password confirmation', function () {
    $user = User::factory()->create(['google_id' => 'google-recovery-subject', 'password' => null]);
    $other = User::factory()->create();
    $owned = seedRecoveryAccountData($user);
    $unrelated = seedRecoveryAccountData($other);
    $phone = $user->createToken('phone');
    $laptop = $user->createToken('laptop');
    $otherToken = $other->createToken('other');
    $password = 'synthetic-recovery-password';

    $this->withToken($phone->plainTextToken)->getJson('/api/v1/me')
        ->assertOk()->assertJsonPath('data.has_password', false);
    $this->app['auth']->forgetGuards();
    $this->deleteJson('/api/v1/me', ['password' => $password])
        ->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->flushHeaders();

    $link = requestGoogleAccountRecovery($this, $user);
    expect($user->fresh()->password)->toBeNull();
    $this->assertModelExists($phone->accessToken);
    $this->assertModelExists($laptop->accessToken);
    foreach ($owned as $model) {
        $this->assertModelExists($model);
    }

    $this->postJson('/api/v1/auth/password/reset', [...$link, 'password' => $password])
        ->assertOk()->assertExactJson(['message' => __('auth_flow.password_reset')]);
    $user->refresh();
    expect(Hash::check($password, $user->password))->toBeTrue()
        ->and($user->google_id)->toBe('google-recovery-subject')
        ->and($user->email)->toBe($link['email'])
        ->and(User::count())->toBe(2)
        ->and($user->tokens()->count())->toBe(0);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);

    foreach ([$phone, $laptop] as $revoked) {
        $this->app['auth']->forgetGuards();
        $this->withToken($revoked->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
    }
    $this->app['auth']->forgetGuards();
    $this->deleteJson('/api/v1/me', ['password' => $password])->assertUnauthorized();
    $this->flushHeaders();

    $login = $this->postJson('/api/v1/auth/login', ['email' => strtoupper($user->email), 'password' => $password])
        ->assertOk()->assertJsonPath('user.id', $user->id);
    $bearer = $login->json('token');
    $secondNewToken = $user->createToken('second-device');
    $this->withToken($bearer)->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.id', $user->id)->assertJsonPath('data.has_password', true);
    $this->flushHeaders();
    $secondLink = requestGoogleAccountRecovery($this, $user);
    $otherLink = requestGoogleAccountRecovery($this, $other);
    expect(Hash::check($password, $user->fresh()->password))->toBeTrue();
    $this->assertModelExists($user);

    $this->app['auth']->forgetGuards();
    $this->withToken($otherToken->plainTextToken)->deleteJson('/api/v1/me', ['password' => $password, 'user_id' => $user->id])
        ->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->app['auth']->forgetGuards();
    $this->withToken($bearer)->deleteJson('/api/v1/me', ['password' => 'wrong-password'])
        ->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->app['auth']->forgetGuards();
    $this->deleteJson('/api/v1/me', ['password' => $secondLink['token']])
        ->assertUnprocessable()->assertJsonValidationErrors('password');

    expect(Hash::check($password, $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(2);
    foreach ($owned as $model) {
        $this->assertModelExists($model);
    }
    $this->assertDatabaseHas('sessions', ['user_id' => $user->id]);
    $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);

    $this->app['auth']->forgetGuards();
    $this->deleteJson('/api/v1/me', ['password' => $password])->assertNoContent();

    foreach ($owned as $model) {
        $this->assertModelMissing($model);
    }
    foreach ($unrelated as $model) {
        $this->assertModelExists($model);
    }
    $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
    $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    $this->assertDatabaseHas('sessions', ['user_id' => $other->id]);
    $this->assertDatabaseHas('password_reset_tokens', ['email' => $other->email]);
    foreach ([$bearer, $secondNewToken->plainTextToken] as $revoked) {
        $this->app['auth']->forgetGuards();
        $this->withToken($revoked)->getJson('/api/v1/me')->assertUnauthorized();
    }
    $this->app['auth']->forgetGuards();
    $this->withToken($otherToken->plainTextToken)->getJson('/api/v1/me')
        ->assertOk()->assertJsonPath('data.id', $other->id);
    $this->flushHeaders();
    $this->postJson('/api/v1/auth/password/reset', [...$otherLink, 'password' => 'unrelated-recovery-password'])
        ->assertOk();
});

it('rejects invalid, cross-account, expired and consumed reset credentials for a Google-only account', function () {
    $user = User::factory()->create(['google_id' => 'google-recovery-subject', 'password' => null]);
    $other = User::factory()->create(['google_id' => 'other-google-subject', 'password' => null]);
    $password = 'synthetic-recovery-password';
    $expired = requestGoogleAccountRecovery($this, $user);

    $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])
        ->assertOk()->assertExactJson(['message' => __('auth_flow.reset_link_sent')]);
    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'unknown@example.test'])
        ->assertOk()->assertExactJson(['message' => __('auth_flow.reset_link_sent')]);
    Mail::assertSent(ResetPasswordMail::class, 1);

    $this->postJson('/api/v1/auth/password/reset', [...$expired, 'token' => 'invalid', 'password' => $password])
        ->assertUnprocessable()->assertJsonValidationErrors('token');
    $this->travel(config('auth.passwords.users.expire') + 1)->minutes();
    $this->postJson('/api/v1/auth/password/reset', [...$expired, 'password' => $password])
        ->assertUnprocessable()->assertJsonValidationErrors('token');
    $valid = requestGoogleAccountRecovery($this, $user);
    requestGoogleAccountRecovery($this, $other);
    $this->postJson('/api/v1/auth/password/reset', [...$valid, 'email' => $other->email, 'password' => $password])
        ->assertUnprocessable()->assertJsonValidationErrors('token');
    expect($user->fresh()->password)->toBeNull()->and($other->fresh()->password)->toBeNull();

    $this->postJson('/api/v1/auth/password/reset', [...$valid, 'password' => $password])->assertOk();
    $this->postJson('/api/v1/auth/password/reset', [...$valid, 'password' => 'different-password'])
        ->assertUnprocessable()->assertJsonValidationErrors('token');
    expect(Hash::check($password, $user->fresh()->password))->toBeTrue();
    $this->travelBack();
});

it('rolls back recovered account deletion when the database refuses to delete the user', function () {
    $user = User::factory()->create(['google_id' => 'google-recovery-subject', 'password' => null]);
    $password = 'synthetic-recovery-password';
    $link = requestGoogleAccountRecovery($this, $user);
    $this->postJson('/api/v1/auth/password/reset', [...$link, 'password' => $password])->assertOk();
    $owned = seedRecoveryAccountData($user);
    $link = requestGoogleAccountRecovery($this, $user);
    $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $password])->assertOk();

    Schema::create('account_deletion_test_holds', function (Blueprint $table) {
        $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
    });
    DB::table('account_deletion_test_holds')->insert(['user_id' => $user->id]);

    config(['app.debug' => false]);
    $this->withToken($login->json('token'))->deleteJson('/api/v1/me', ['password' => $password])
        ->assertInternalServerError()->assertJsonStructure(['message'])->assertJsonMissingPath('trace');

    foreach ($owned as $model) {
        $this->assertModelExists($model);
    }
    $this->assertDatabaseHas('sessions', ['user_id' => $user->id]);
    $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    expect(Hash::check($password, $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->google_id)->toBe('google-recovery-subject');
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);

    $this->flushHeaders();
    $this->postJson('/api/v1/auth/password/reset', [...$link, 'password' => $password])->assertOk();
});
