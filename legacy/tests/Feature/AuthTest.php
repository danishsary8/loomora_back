<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NotificationSetting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_user_and_returns_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test Student',
            'email' => 'student@example.com',
            'password' => 'Str0ng-Routine-Pass!42',
            'password_confirmation' => 'Str0ng-Routine-Pass!42',
            'terms_accepted' => true,
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'email', 'created_at'],
                'meta' => ['token', 'token_type'],
            ])
            ->assertJsonPath('data.email', 'student@example.com')
            ->assertJsonPath('meta.token_type', 'Bearer');

        $this->assertDatabaseHas('users', ['email' => 'student@example.com']);

        // Password must never be returned, and must be hashed at rest.
        $response->assertJsonMissingPath('data.password');
        $this->assertNotSame('Str0ng-Routine-Pass!42', User::first()->password);

        // Defaults are provisioned on registration.
        $this->assertDatabaseCount(NotificationSetting::class, 1);
        $this->assertDatabaseCount('day_groups', 0);
    }

    public function test_register_rejects_duplicate_email_with_422_envelope(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Someone Else',
            'email' => 'taken@example.com',
            'password' => 'Str0ng-Routine-Pass!42',
            'password_confirmation' => 'Str0ng-Routine-Pass!42',
            'terms_accepted' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['email']]]);
    }

    public function test_register_rejects_password_shorter_than_eight_characters(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Weak Pass',
            'email' => 'weak@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
            'terms_accepted' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_login_returns_token_for_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => 'Str0ng-Routine-Pass!42',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.com',
            'password' => 'Str0ng-Routine-Pass!42',
        ])
            ->assertOk()
            ->assertJsonPath('data.email', 'login@example.com')
            ->assertJsonStructure(['data' => ['id', 'email'], 'meta' => ['token', 'token_type']]);
    }

    public function test_login_with_wrong_password_returns_401_envelope(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => 'Str0ng-Routine-Pass!42',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.com',
            'password' => 'totally-wrong-password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_credentials')
            ->assertJsonStructure(['error' => ['code', 'message']]);
    }

    public function test_full_register_login_access_logout_flow(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Flow User',
            'email' => 'flow@example.com',
            'password' => 'Str0ng-Routine-Pass!42',
            'password_confirmation' => 'Str0ng-Routine-Pass!42',
            'terms_accepted' => true,
        ])->assertCreated();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'flow@example.com',
            'password' => 'Str0ng-Routine-Pass!42',
        ])->assertOk()->json('meta.token');

        $this->assertIsString($token);

        // Token grants access to a protected endpoint.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'flow@example.com');

        // Logout revokes that token.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('data.message', 'Logged out successfully.');

        $this->assertDatabaseCount('personal_access_tokens', 1); // registration token remains
    }

    public function test_unauthenticated_me_returns_401_not_a_stack_trace(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated')
            ->assertJsonMissingPath('exception');
    }

    public function test_health_endpoint_is_public(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson(['data' => ['status' => 'ok', 'database' => 'ok', 'schema' => 'ok']]);
    }

    public function test_health_reports_an_unreachable_database_without_failing_the_probe(): void
    {
        // Render's health check points at this path. Returning a failure status
        // would pull a container that can still explain itself out of service.
        DB::shouldReceive('connection')->once()->andThrow(new QueryException('pgsql', 'select 1', [], new RuntimeException('could not translate host name')));

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson(['data' => ['status' => 'ok', 'database' => 'unavailable', 'schema' => 'unknown']]);
    }

    public function test_health_separates_a_connected_database_from_a_migrated_one(): void
    {
        /*
         * The state a deploy lands in when migrations are skipped: the connection
         * works, so `select 1` passes, but no tables exist and every data endpoint
         * returns 500. Indistinguishable from a broken API without this field.
         */
        Schema::shouldReceive('hasTable')->with('migrations')->andReturn(false);

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson(['data' => ['status' => 'ok', 'database' => 'ok', 'schema' => 'missing']]);
    }

    public function test_error_responses_carry_cors_headers_for_the_browser_to_read(): void
    {
        /*
         * A rendered failure that reaches the browser without
         * Access-Control-Allow-Origin is discarded before the SPA can read it, so
         * the user is shown "cannot reach the server" for a server that answered.
         */
        config(['cors.allowed_origins' => ['https://daycraft.example']]);

        $this->withHeader('Origin', 'https://daycraft.example')
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertHeader('Access-Control-Allow-Origin', 'https://daycraft.example');
    }

    public function test_error_responses_never_echo_a_foreign_origin_back(): void
    {
        /*
         * With a single allowed origin configured, CorsService advertises that
         * origin rather than the caller's. The browser compares it against its own
         * origin and blocks the response, which is the outcome we want — what must
         * never happen is the foreign origin being reflected back as permitted.
         */
        config(['cors.allowed_origins' => ['https://daycraft.example']]);

        $response = $this->withHeader('Origin', 'https://not-mine.example')
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);

        $this->assertNotSame('https://not-mine.example', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }
}
