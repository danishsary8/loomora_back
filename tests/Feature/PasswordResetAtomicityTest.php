<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;

uses(DatabaseMigrations::class);

it('rolls back every credential change when reset cannot delete a required row', function (string $blockedTable) {
    $user = User::factory()->create(['google_id' => 'synthetic-linked-subject', 'password' => 'original-password']);
    $phone = $user->createToken('phone');
    $laptop = $user->createToken('laptop');
    $other = User::factory()->create();
    $otherBearer = $other->createToken('unrelated');
    $credentials = ['email' => $user->email, 'token' => Password::createToken($user), 'password' => 'new-password'];
    $originalHash = $user->password;
    $resetRow = (array) DB::table('password_reset_tokens')->where('email', $user->email)->first();

    Schema::create('reset_test_holds', function (Blueprint $table) use ($blockedTable) {
        if ($blockedTable === 'personal_access_tokens') {
            $table->foreignId('blocked')->constrained($blockedTable)->restrictOnDelete();
        } else {
            $table->string('blocked');
            $table->foreign('blocked')->references('email')->on($blockedTable)->restrictOnDelete();
        }
    });
    DB::table('reset_test_holds')->insert([
        'blocked' => $blockedTable === 'personal_access_tokens' ? $phone->accessToken->id : $user->email,
    ]);

    config(['app.debug' => false]);

    try {
        $this->postJson('/api/v1/auth/password/reset', $credentials)
            ->assertInternalServerError()->assertExactJson(['message' => 'Server Error']);
    } finally {
        Schema::drop('reset_test_holds');
    }

    $user->refresh();
    expect([
        'original password hash' => $user->password === $originalHash,
        'phone bearer' => $phone->accessToken->fresh() !== null,
        'laptop bearer' => $laptop->accessToken->fresh() !== null,
        'original reset row' => (array) DB::table('password_reset_tokens')->where('email', $user->email)->first() === $resetRow,
    ])->toBe([
        'original password hash' => true,
        'phone bearer' => true,
        'laptop bearer' => true,
        'original reset row' => true,
    ]);
    expect($user->google_id)->toBe('synthetic-linked-subject')
        ->and($user->email)->toBe($credentials['email']);
    $this->assertModelExists($otherBearer->accessToken);

    $this->postJson('/api/v1/auth/password/reset', $credentials)
        ->assertOk()->assertExactJson(['message' => __('auth_flow.password_reset')]);
    expect(Hash::check($credentials['password'], $user->fresh()->password))->toBeTrue();
    $this->assertModelMissing($phone->accessToken);
    $this->assertModelMissing($laptop->accessToken);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    $this->assertModelExists($otherBearer->accessToken);
})->with(['bearer revocation' => 'personal_access_tokens', 'reset token consumption' => 'password_reset_tokens']);

it('cleans up the configured database reset token table on account deletion', function () {
    $tableName = 'configured_reset_tokens';
    Schema::create($tableName, function (Blueprint $table) {
        $table->string('email')->primary();
        $table->string('token');
        $table->timestamp('created_at')->nullable();
    });
    config(['auth.passwords.users.table' => $tableName]);

    try {
        $user = User::factory()->create(['password' => 'original-password']);
        $bearer = $user->createToken('delete');
        $token = Password::createToken($user);

        $this->withToken($bearer->plainTextToken)
            ->deleteJson('/api/v1/me', ['password' => 'original-password'])->assertNoContent();
        $this->assertDatabaseMissing($tableName, ['email' => $user->email]);
        $this->assertModelMissing($bearer->accessToken);

        $this->flushHeaders();
        User::factory()->create(['email' => $user->email]);
        $this->postJson('/api/v1/auth/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('token');
    } finally {
        Schema::drop($tableName);
    }
});
