<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ChecklistLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_use_numeric_owned_item_ids_and_persist(): void
    {
        $u = User::factory()->create();
        Sanctum::actingAs($u);
        $g = $this->postJson('/api/v1/day-groups', ['name' => 'Monday', 'weekdays' => [1]])->json('data.id');
        $i = $this->postJson("/api/v1/day-groups/$g/checklist-items", ['label' => 'Hydrate'])->json('data.id');
        $this->putJson('/api/v1/checklist/2026-08-03', ['checked_ids' => [$i]])->assertOk()->assertJsonPath('data.checked_ids.0', $i);
        $this->getJson('/api/v1/checklist/2026-08-03')->assertOk()->assertJsonPath('data.completed', 1);
    }

    public function test_user_cannot_log_another_users_item(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        Sanctum::actingAs($a);
        $g = $this->postJson('/api/v1/day-groups', ['name' => 'Monday', 'weekdays' => [1]])->json('data.id');
        $i = $this->postJson("/api/v1/day-groups/$g/checklist-items", ['label' => 'Private'])->json('data.id');
        Sanctum::actingAs($b);
        $this->putJson('/api/v1/checklist/2026-08-03', ['checked_ids' => [$i]])->assertStatus(422);
    }
}
