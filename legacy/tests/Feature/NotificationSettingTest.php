<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class NotificationSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_settings_creates_defaults_on_first_access(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/notification-settings')
            ->assertOk()
            ->assertJsonStructure(['data' => ['enabled', 'minutes_before', 'updated_at']])
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.minutes_before', 5);
    }

    public function test_update_settings_persists_changes(): void
    {
        Sanctum::actingAs($user = User::factory()->create());

        $this->putJson('/api/v1/notification-settings', [
            'enabled' => false,
            'minutes_before' => 15,
        ])
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.minutes_before', 15);

        $this->assertDatabaseHas('notification_settings', [
            'user_id' => $user->id,
            'enabled' => false,
            'minutes_before' => 15,
        ]);
    }

    public function test_update_rejects_out_of_range_minutes(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/notification-settings', ['minutes_before' => 999])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['minutes_before']]]);
    }

    public function test_settings_require_authentication(): void
    {
        $this->getJson('/api/v1/notification-settings')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }
}
