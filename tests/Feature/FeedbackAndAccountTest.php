<?php

use App\Mail\FeedbackReceivedMail;
use App\Models\Feedback;
use App\Models\ScheduleItem;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    config(['services.feedback.to' => 'danish@example.com']);
});

describe('feedback', function () {
    it('stores feedback from a signed-in user and emails it', function () {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/feedback', ['message' => '  The ring is lovely  '])
            ->assertCreated()
            ->assertJsonStructure(['message']);

        $feedback = Feedback::sole();
        expect($feedback->user_id)->toBe($user->id)->and($feedback->message)->toBe('The ring is lovely');

        Mail::assertSent(FeedbackReceivedMail::class, fn (FeedbackReceivedMail $mail) => $mail->hasTo('danish@example.com')
            && $mail->hasReplyTo($user->email));
    });

    it('accepts feedback from a guest', function () {
        $this->postJson('/api/v1/feedback', ['message' => 'Hello from a guest'])->assertCreated();

        expect(Feedback::sole()->user_id)->toBeNull();
        Mail::assertSent(FeedbackReceivedMail::class);
    });

    it('still stores feedback when no recipient is configured or mail fails', function () {
        config(['services.feedback.to' => null]);
        $this->postJson('/api/v1/feedback', ['message' => 'One'])->assertCreated();
        Mail::assertNothingSent();

        config(['services.feedback.to' => 'danish@example.com']);
        Mail::shouldReceive('to')->andThrow(new RuntimeException('Brevo down'));
        $this->postJson('/api/v1/feedback', ['message' => 'Two'])->assertCreated();

        expect(Feedback::count())->toBe(2);
    });

    it('validates the message', function () {
        $this->postJson('/api/v1/feedback', ['message' => '   '])->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->postJson('/api/v1/feedback', ['message' => str_repeat('a', 5001)])->assertUnprocessable();
    });

    it('limits feedback to 10 per hour', function () {
        foreach (range(1, 10) as $i) {
            $this->postJson('/api/v1/feedback', ['message' => "Note {$i}"])->assertCreated();
        }

        $this->postJson('/api/v1/feedback', ['message' => 'Too many'])->assertTooManyRequests();
    });

    it('renders the email with sender and message', function () {
        $user = User::factory()->create(['name' => 'Sokha', 'email' => 'sokha@example.com']);
        $feedback = new Feedback(['message' => 'Please add dark mode & "themes"']);
        $feedback->user()->associate($user);
        $feedback->save();

        expect((new FeedbackReceivedMail($feedback))->render())
            ->toContain('Sokha <sokha@example.com>', 'Please add dark mode & "themes"', $feedback->id)
            ->not->toContain('&lt;');
    });
});

describe('PATCH /me', function () {
    it('updates name and time zone only', function () {
        $user = User::factory()->create(['email' => 'sokha@example.com']);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/me', ['name' => 'Sokha K', 'timezone' => 'Europe/Paris', 'email' => 'evil@example.com', 'current_streak' => 50])
            ->assertOk()
            ->assertJsonPath('data.name', 'Sokha K')
            ->assertJsonPath('data.timezone', 'Europe/Paris')
            ->assertJsonPath('data.email', 'sokha@example.com')
            ->assertJsonPath('data.current_streak', 0);
    });

    it('validates name and time zone', function () {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->patchJson('/api/v1/me', ['name' => '', 'timezone' => 'Mars/Base'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'timezone']);
    });
});

describe('POST /me/survey', function () {
    it('saves answers and marks the survey done', function () {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/me/survey', ['goal' => 'manage_time', 'heard_from' => 'A friend'])
            ->assertOk()
            ->assertJsonPath('data.goal', 'manage_time')
            ->assertJsonPath('data.heard_from', 'A friend')
            ->assertJsonPath('data.survey_done', true);
    });

    it('can be skipped with an empty submit', function () {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/me/survey', [])
            ->assertOk()
            ->assertJsonPath('data.goal', null)
            ->assertJsonPath('data.survey_done', true);
    });

    it('rejects an unknown goal', function () {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/me/survey', ['goal' => 'get_rich'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('goal');
    });
});

describe('DELETE /me', function () {
    it('requires a valid bearer token before checking the password', function () {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);
        $payload = ['password' => 'correct-horse-battery', 'user_id' => $user->id];

        $this->delete('/api/v1/me', $payload)
            ->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $this->withToken('invalid-token')->delete('/api/v1/me', $payload)
            ->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);

        $this->assertModelExists($user);
    });

    it('needs the right password', function () {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/me', ['password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/me', [])
            ->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertModelExists($user);
    });

    it('refuses Google-only accounts until Google re-auth exists', function () {
        $user = User::factory()->create(['password' => null, 'google_id' => 'g-1']);

        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/me', ['password' => 'anything'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertModelExists($user);
    });

    it('deletes the user and leaves no rows behind', function () {
        $user = User::factory()->create(['email' => 'sokha@example.com', 'password' => 'correct-horse-battery']);
        $other = User::factory()->create();

        $token = $user->createToken('phone')->plainTextToken;
        $secondToken = $user->createToken('laptop')->plainTextToken;
        $otherToken = $other->createToken('theirs')->plainTextToken;
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'x', 'created_at' => now()]);
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => 0]);
        (new Feedback(['message' => 'Mine']))->user()->associate($user)->save();
        (new Feedback(['message' => 'Theirs']))->user()->associate($other)->save();
        $item = ScheduleItem::factory()->for($user)->create();
        $exception = $item->exceptions()->create(['date' => '2026-11-20', 'type' => 'skip']);
        $checkin = $item->checkins()->create(['date' => '2026-11-19', 'done_at' => now()]);
        $user->dailyIntentions()->create(['local_date' => '2026-11-20', 'text' => 'My intention']);
        $parent = Todo::factory()->for($user)->create();
        $subtask = Todo::factory()->for($user)->create(['parent_id' => $parent->id]);
        $subtask->delete();
        $otherTodo = Todo::factory()->for($other)->create();

        $this->withToken($token)->deleteJson('/api/v1/me', [
            'password' => 'correct-horse-battery',
            'user_id' => $other->id,
        ])->assertNoContent();

        // Every table that can hold this user's data is empty of it.
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            $columns = Schema::getColumnListing($name);
            $query = DB::table($name);

            if (in_array('user_id', $columns)) {
                expect($query->clone()->where('user_id', $user->id)->count())->toBe(0, "{$name}.user_id");
            }
            if (in_array('tokenable_id', $columns)) {
                expect($query->clone()->where('tokenable_id', $user->id)->count())->toBe(0, "{$name}.tokenable_id");
            }
            if (in_array('email', $columns)) {
                expect($query->clone()->where('email', $user->email)->count())->toBe(0, "{$name}.email");
            }
        }

        $this->assertModelMissing($user);
        $this->assertModelMissing($exception);
        $this->assertModelMissing($checkin);
        $this->assertModelExists($other);
        $this->assertModelExists($otherTodo);
        expect(Feedback::where('user_id', $other->id)->count())->toBe(1)
            ->and($other->tokens()->count())->toBe(1);

        foreach ([$token, $secondToken] as $revokedToken) {
            $this->app['auth']->forgetGuards();
            $this->withToken($revokedToken)->getJson('/api/v1/me')->assertUnauthorized();
        }

        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $other->id);
    });

    it('rolls back cleanup when the user deletion is cancelled', function () {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);
        $token = $user->createToken('phone');
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'reset', 'created_at' => now()]);
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => 0]);
        $todo = Todo::factory()->for($user)->create();
        User::deleting(fn (User $deleting) => $deleting->is($user) ? false : null);

        config(['app.debug' => false]);
        $response = $this->withToken($token->plainTextToken)
            ->deleteJson('/api/v1/me', ['password' => 'correct-horse-battery']);

        $this->assertModelExists($user);
        $this->assertModelExists($todo);
        $this->assertModelExists($token->accessToken);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseHas('sessions', ['id' => 's1', 'user_id' => $user->id]);
        $response->assertInternalServerError()->assertJsonStructure(['message'])->assertJsonMissingPath('trace');
    });
});
