<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class DayGroupCrudTest extends TestCase
{
    use RefreshDatabase;

    private function createGroup(User $user, array $weekdays = [1, 2, 3, 4, 5]): int
    {
        Sanctum::actingAs($user);

        return (int) $this->postJson('/api/v1/day-groups', ['name' => 'Weekdays', 'color' => '#0F766E', 'weekdays' => $weekdays])->assertCreated()->json('data.id');
    }

    public function test_new_user_has_no_schedule(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/day-groups')->assertOk()->assertExactJson(['data' => []]);
        $this->assertDatabaseCount('day_groups', 0);
        $this->assertDatabaseCount('timeline_entries', 0);
        $this->assertDatabaseCount('checklist_items', 0);
    }

    public function test_day_group_weekdays_and_child_crud(): void
    {
        $u = User::factory()->create();
        $id = $this->createGroup($u, [1, 3, 5]);
        $this->assertDatabaseHas('day_group_weekdays', ['day_group_id' => $id, 'weekday' => 3]);
        $entry = $this->postJson("/api/v1/day-groups/$id/timeline-entries", ['start_time' => '09:00', 'end_time' => '10:30', 'description' => 'Deep work', 'category' => 'Career'])->assertCreated()->json('data.id');
        $item = $this->postJson("/api/v1/day-groups/$id/checklist-items", ['label' => 'Plan the day'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/timeline-entries/$entry", ['description' => 'Focused work'])->assertOk()->assertJsonPath('data.description', 'Focused work');
        $this->patchJson("/api/v1/checklist-items/$item", ['label' => 'Review the day'])->assertOk()->assertJsonPath('data.label', 'Review the day');
        $this->patchJson("/api/v1/day-groups/$id", ['name' => 'Focus days', 'weekdays' => [2, 4]])->assertOk()->assertJsonPath('data.name', 'Focus days');
        $this->deleteJson("/api/v1/timeline-entries/$entry")->assertOk();
        $this->deleteJson("/api/v1/checklist-items/$item")->assertOk();
    }

    public function test_delete_requires_confirmation_and_cascades(): void
    {
        $u = User::factory()->create();
        $id = $this->createGroup($u);
        $this->postJson("/api/v1/day-groups/$id/timeline-entries", ['start_time' => '09:00', 'end_time' => '10:00', 'description' => 'Work', 'category' => 'Career']);
        $this->postJson("/api/v1/day-groups/$id/checklist-items", ['label' => 'Prepare']);
        $this->deleteJson("/api/v1/day-groups/$id")->assertStatus(422);
        $this->deleteJson("/api/v1/day-groups/$id?confirm=true")->assertOk();
        $this->assertDatabaseCount('day_groups', 0);
        $this->assertDatabaseCount('timeline_entries', 0);
        $this->assertDatabaseCount('checklist_items', 0);
    }

    public function test_cross_user_resources_are_not_visible_or_mutable(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $id = $this->createGroup($a, [1]);
        $entry = $this->postJson("/api/v1/day-groups/$id/timeline-entries", ['start_time' => '09:00', 'end_time' => '10:00', 'description' => 'Private', 'category' => 'Life'])->json('data.id');
        $item = $this->postJson("/api/v1/day-groups/$id/checklist-items", ['label' => 'Private item'])->json('data.id');
        Sanctum::actingAs($b);
        $this->getJson("/api/v1/schedule/day-groups/$id")->assertNotFound();
        $this->getJson("/api/v1/day-groups/$id/timeline-entries")->assertNotFound();
        $this->patchJson("/api/v1/day-groups/$id", ['name' => 'Stolen'])->assertNotFound();
        $this->patchJson("/api/v1/timeline-entries/$entry", ['description' => 'Stolen'])->assertNotFound();
        $this->patchJson("/api/v1/checklist-items/$item", ['label' => 'Stolen'])->assertNotFound();
        $this->deleteJson("/api/v1/day-groups/$id?confirm=true")->assertNotFound();
    }

    public function test_today_returns_clear_empty_state_for_unassigned_weekday(): void
    {
        $u = User::factory()->create();
        Sanctum::actingAs($u);
        $this->getJson('/api/v1/schedule/today?date=2026-08-03')->assertOk()->assertJsonPath('data.assigned', false)->assertJsonPath('data.groups', [])->assertJsonPath('data.message', 'No day group is assigned to this weekday.');
    }

    public function test_today_returns_all_owned_groups_assigned_to_weekday(): void
    {
        $u = User::factory()->create();
        $id = $this->createGroup($u, [1]);
        $this->postJson("/api/v1/day-groups/$id/timeline-entries", ['start_time' => '09:00', 'end_time' => '10:00', 'description' => 'Build', 'category' => 'Career']);
        $this->getJson('/api/v1/schedule/today?date=2026-08-03')->assertOk()->assertJsonPath('data.assigned', true)->assertJsonPath('data.groups.0.name', 'Weekdays')->assertJsonPath('data.timeline.0.description', 'Build');
    }
}
