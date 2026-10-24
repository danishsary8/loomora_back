<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Locks the two-tier category contract in App\Enums\Category.
 *
 * Retired categories must be rejected for new writes while remaining readable,
 * because the timeline_entries.category column is a plain string and the model
 * casts it to this enum. Dropping a retired case would make Eloquent throw a
 * ValueError while hydrating pre-existing rows.
 */
final class CategoryContractTest extends TestCase
{
    use RefreshDatabase;

    private function createGroup(User $user, array $weekdays = [1]): int
    {
        Sanctum::actingAs($user);

        return (int) $this->postJson('/api/v1/day-groups', [
            'name' => 'Weekdays',
            'color' => '#0F766E',
            'weekdays' => $weekdays,
        ])->assertCreated()->json('data.id');
    }

    public function test_retired_categories_are_excluded_from_the_selectable_set(): void
    {
        $this->assertSame(['Career', 'Health', 'Language', 'Life', 'Rest'], Category::selectableValues());

        // Retired cases must still be declared so historical rows can hydrate.
        $this->assertContains('Faith', Category::values());
        $this->assertNotContains('Faith', Category::selectableValues());
        $this->assertFalse(Category::Faith->isSelectable());
        $this->assertTrue(Category::Life->isSelectable());
    }

    public function test_every_selectable_category_is_accepted_on_create(): void
    {
        $user = User::factory()->create();
        $groupId = $this->createGroup($user);

        foreach (Category::selectableValues() as $index => $value) {
            $this->postJson("/api/v1/day-groups/$groupId/timeline-entries", [
                'start_time' => sprintf('%02d:00', $index + 8),
                'end_time' => sprintf('%02d:30', $index + 8),
                'description' => "Block $value",
                'category' => $value,
            ])->assertCreated()->assertJsonPath('data.category', $value);
        }
    }

    public function test_retired_category_is_rejected_on_create_and_update(): void
    {
        $user = User::factory()->create();
        $groupId = $this->createGroup($user);

        $this->postJson("/api/v1/day-groups/$groupId/timeline-entries", [
            'start_time' => '04:40',
            'end_time' => '05:30',
            'description' => 'Legacy block',
            'category' => 'Faith',
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

        $entryId = $this->postJson("/api/v1/day-groups/$groupId/timeline-entries", [
            'start_time' => '09:00',
            'end_time' => '10:00',
            'description' => 'Deep work',
            'category' => 'Career',
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/timeline-entries/$entryId", ['category' => 'Faith'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        // The rejected update must not have altered the stored value.
        $this->assertDatabaseHas('timeline_entries', ['id' => $entryId, 'category' => 'Career']);
    }

    public function test_pre_existing_retired_category_rows_remain_readable(): void
    {
        $user = User::factory()->create();
        $groupId = $this->createGroup($user);

        // Written straight to the table to simulate a row created before the
        // category was retired, bypassing current validation.
        DB::table('timeline_entries')->insert([
            'day_group_id' => $groupId,
            'start_time' => '04:40:00',
            'end_time' => '05:30:00',
            'category' => 'Faith',
            'description' => 'Legacy morning block',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson("/api/v1/day-groups/$groupId/timeline-entries")
            ->assertOk()
            ->assertJsonPath('data.0.category', 'Faith');

        // 2026-08-03 is a Monday, matching the group's weekday assignment.
        $this->getJson('/api/v1/schedule/today?date=2026-08-03')
            ->assertOk()
            ->assertJsonPath('data.assigned', true)
            ->assertJsonPath('data.timeline.0.description', 'Legacy morning block');
    }

    public function test_a_legacy_row_can_still_be_edited_without_resending_its_category(): void
    {
        $user = User::factory()->create();
        $groupId = $this->createGroup($user);

        $entryId = (int) DB::table('timeline_entries')->insertGetId([
            'day_group_id' => $groupId,
            'start_time' => '04:40:00',
            'end_time' => '05:30:00',
            'category' => 'Faith',
            'description' => 'Legacy morning block',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // `category` is `sometimes`, so omitting it preserves the retired value
        // and lets users keep maintaining historical entries.
        $this->patchJson("/api/v1/timeline-entries/$entryId", ['description' => 'Renamed block'])
            ->assertOk()
            ->assertJsonPath('data.description', 'Renamed block')
            ->assertJsonPath('data.category', 'Faith');
    }
}
