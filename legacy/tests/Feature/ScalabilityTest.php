<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DayGroup;
use App\Models\User;
use App\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ScalabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_query_count_stays_constant_with_multiple_groups_and_children(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 4) as $position) {
            $group = DayGroup::create(['user_id' => $user->id, 'name' => "Group {$position}", 'sort_order' => $position]);
            $group->syncWeekdays([1]);
            foreach (range(1, 3) as $entry) {
                $group->timelineEntries()->create(['start_time' => '09:00', 'end_time' => '10:00', 'description' => "Entry {$entry}", 'category' => 'Career', 'sort_order' => $entry]);
                $group->checklistItems()->create(['label' => "Habit {$entry}", 'sort_order' => $entry]);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ScheduleService::class)->today($user, Carbon::parse('2026-08-03'));
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        fwrite(STDOUT, "\nSCHEDULE_TODAY_QUERY_COUNT={$count}\n");
        self::assertSame(5, $count);
    }

    public function test_schedule_cache_is_invalidated_immediately_after_entry_edit(): void
    {
        $user = User::factory()->create();
        $group = DayGroup::create(['user_id' => $user->id, 'name' => 'Monday', 'sort_order' => 0]);
        $group->syncWeekdays([1]);
        $entry = $group->timelineEntries()->create(['start_time' => '09:00', 'end_time' => '10:00', 'description' => 'Original', 'category' => 'Career', 'sort_order' => 0]);

        Sanctum::actingAs($user);
        $url = '/api/v1/schedule/today?date=2026-08-03';
        $this->getJson($url)->assertOk()->assertJsonPath('data.timeline.0.description', 'Original');
        $this->patchJson("/api/v1/timeline-entries/{$entry->id}", ['description' => 'Updated immediately'])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.timeline.0.description', 'Updated immediately');
    }
}
