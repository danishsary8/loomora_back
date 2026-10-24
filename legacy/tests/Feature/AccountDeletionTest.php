<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChecklistItem;
use App\Models\ChecklistLog;
use App\Models\DayGroup;
use App\Models\NotificationSetting;
use App\Models\PrayerTimeCache;
use App\Models\TimelineEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_deletion_requires_authentication(): void
    {
        $this->deleteJson('/api/v1/account', ['password' => 'irrelevant'])->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_password_backed_account_must_supply_its_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Str0ng-Routine-Pass!42')]);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/account', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['password']]]);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_account_deletion_rejects_a_wrong_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Str0ng-Routine-Pass!42')]);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/account', ['password' => 'not-my-password'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_typing_the_confirmation_word_is_not_enough_for_a_password_account(): void
    {
        // The old contract accepted this; a password account must now re-authenticate.
        $user = User::factory()->create(['password' => Hash::make('Str0ng-Routine-Pass!42')]);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/account', ['confirmation' => 'DELETE'])->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_account_deletion_accepts_the_correct_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Str0ng-Routine-Pass!42')]);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/account', ['password' => 'Str0ng-Routine-Pass!42'])->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_google_only_account_can_still_delete_by_typing_the_confirmation_word(): void
    {
        /*
         * Google sign-up stores a null password. If deletion demanded a password
         * these accounts could never be deleted, so they keep the typed
         * confirmation. This test is the guard against that regression.
         */
        $user = User::factory()->create(['password' => null, 'google_id' => 'google-123']);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/account', ['confirmation' => 'delete'])->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $user->id]);

        $this->deleteJson('/api/v1/account', ['confirmation' => 'DELETE'])->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_account_deletion_permanently_removes_every_owned_record(): void
    {
        $user = User::factory()->create(['email' => 'delete@example.com', 'password' => Hash::make('Str0ng-Routine-Pass!42')]);
        $group = DayGroup::create(['user_id' => $user->id, 'name' => 'Private routine', 'color' => '#0F766E', 'sort_order' => 0]);
        $group->syncWeekdays([1]);
        $entry = TimelineEntry::create(['day_group_id' => $group->id, 'start_time' => '09:00', 'end_time' => '10:00', 'category' => 'Life', 'description' => 'Private block']);
        $item = ChecklistItem::create(['day_group_id' => $group->id, 'label' => 'Private habit']);
        ChecklistLog::create(['user_id' => $user->id, 'checklist_item_id' => $item->id, 'log_date' => '2026-08-03', 'is_checked' => true]);
        NotificationSetting::create(['user_id' => $user->id]);
        PrayerTimeCache::create(['user_id' => $user->id, 'cache_date' => '2026-08-03', 'latitude' => 11.5, 'longitude' => 104.9, 'fajr' => '05:00', 'dhuhr' => '12:00', 'asr' => '15:30', 'maghrib' => '18:15', 'isha' => '19:30']);
        $user->createToken('existing-session');
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'hashed-token', 'created_at' => now()]);
        DB::table('sessions')->insert(['id' => 'session-id', 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => 'payload', 'last_activity' => time()]);
        Sanctum::actingAs($user);
        $this->deleteJson('/api/v1/account', ['password' => 'Str0ng-Routine-Pass!42'])->assertOk();
        foreach (['users', 'day_groups', 'day_group_weekdays', 'timeline_entries', 'checklist_items', 'checklist_logs', 'notification_settings', 'prayer_time_cache', 'personal_access_tokens', 'password_reset_tokens', 'sessions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
