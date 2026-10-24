<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use App\Models\ChecklistItem;
use App\Models\ChecklistLog;
use App\Models\DayGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pageviews_are_sanitized_and_idempotent_without_pii(): void
    {
        $key = '11111111-1111-4111-8111-111111111111';
        $payload = ['path' => '/privacy?email=private@example.com', 'idempotency_key' => $key, 'properties' => ['email' => 'private@example.com']];
        $this->postJson('/api/v1/analytics/page-view', $payload)->assertOk();
        $this->postJson('/api/v1/analytics/page-view', $payload)->assertOk();

        $this->assertDatabaseCount('analytics_events', 1);
        $event = AnalyticsEvent::firstOrFail();
        $this->assertSame('page_view', $event->event_name);
        $this->assertSame('/privacy', $event->path);
        $this->assertNull($event->properties);
        $this->assertStringNotContainsString('private', (string) $event->dedupe_key);

        $this->postJson('/api/v1/analytics/page-view', ['path' => '/users/private@example.com'])->assertOk();
        $this->assertSame('/other', AnalyticsEvent::latest('id')->value('path'));
    }

    public function test_client_event_allowlist_rejects_arbitrary_or_pii_events(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/analytics/event', ['event' => 'email_viewed', 'idempotency_key' => '22222222-2222-4222-8222-222222222222'])->assertUnprocessable();
        $this->postJson('/api/v1/analytics/event', ['event' => 'notification_permission_granted', 'idempotency_key' => '33333333-3333-4333-8333-333333333333', 'properties' => ['email' => 'private@example.com']])->assertOk();
        $event = AnalyticsEvent::firstOrFail();
        $this->assertNull($event->properties);
    }

    public function test_server_events_fire_once_at_successful_business_transitions(): void
    {
        $registration = $this->postJson('/api/v1/auth/register', ['name' => 'Analytics User', 'email' => 'analytics@example.com', 'password' => 'StrongPass9', 'password_confirmation' => 'StrongPass9', 'terms_accepted' => true])->assertCreated();
        $user = User::findOrFail($registration->json('data.id'));
        $user->forceFill(['email_verified_at' => now()])->save();
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/v1/day-groups', ['name' => 'First', 'weekdays' => [1]])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/day-groups', ['name' => 'Second', 'weekdays' => [2]])->assertCreated();
        $item = $this->postJson("/api/v1/day-groups/{$first}/checklist-items", ['label' => 'Private label'])->assertCreated()->json('data.id');
        $this->putJson('/api/v1/checklist/2026-08-10', ['checked_ids' => [$item]])->assertOk();
        $this->putJson('/api/v1/checklist/2026-08-10', ['checked_ids' => [$item]])->assertOk();
        $this->deleteJson("/api/v1/day-groups/{$first}?confirm=true")->assertOk();
        // Password-backed account, so deletion re-authenticates with the password.
        $this->deleteJson('/api/v1/account', ['password' => 'StrongPass9'])->assertOk();

        $this->assertSame(1, AnalyticsEvent::where('event_name', 'signup_completed')->count());
        $this->assertSame('email', AnalyticsEvent::where('event_name', 'signup_completed')->firstOrFail()->properties['method']);
        $this->assertSame(1, AnalyticsEvent::where('event_name', 'first_day_group_created')->count());
        $this->assertSame(1, AnalyticsEvent::where('event_name', 'checklist_item_checked')->count());
        $this->assertSame(1, AnalyticsEvent::where('event_name', 'day_group_deleted')->count());
        $this->assertSame(1, AnalyticsEvent::where('event_name', 'account_deleted')->count());
        $this->assertStringNotContainsString('analytics@example.com', AnalyticsEvent::get()->toJson());
    }

    public function test_internal_summary_is_admin_gated_and_matches_database_aggregates(): void
    {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $other = User::factory()->create(['email' => 'member@example.com']);
        $group = DayGroup::create(['user_id' => $admin->id, 'name' => 'Admin group', 'sort_order' => 0]);
        $item = ChecklistItem::create(['day_group_id' => $group->id, 'label' => 'Measure']);
        ChecklistLog::create(['user_id' => $admin->id, 'checklist_item_id' => $item->id, 'log_date' => now()->toDateString(), 'is_checked' => true]);
        ChecklistLog::create(['user_id' => $admin->id, 'checklist_item_id' => $item->id, 'log_date' => now()->subDay()->toDateString(), 'is_checked' => false]);
        AnalyticsEvent::create(['event_name' => 'page_view', 'path' => '/dashboard', 'occurred_at' => now()]);
        config()->set('analytics.admin_emails', ['owner@example.com']);

        Sanctum::actingAs($other);
        $this->getJson('/api/v1/internal/analytics')->assertForbidden();
        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/internal/analytics')->assertOk()
            ->assertJsonPath('data.total_users', 2)
            ->assertJsonPath('data.total_day_groups', 1)
            ->assertJsonPath('data.checklist_last_7_days.completed', 1)
            ->assertJsonPath('data.checklist_last_7_days.total', 2)
            ->assertJsonPath('data.checklist_last_7_days.completion_rate', 50);
        $this->assertSame(1, $response->json('data.pageviews_last_30_days')['/dashboard']);
    }
}
