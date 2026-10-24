<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PrayerTimeCache;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PrayerTimeTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-07-27';

    /**
     * @return array<string, mixed>
     */
    private function aladhanPayload(): array
    {
        return [
            'data' => [
                'timings' => [
                    'Fajr' => '04:32 (+07)',
                    'Dhuhr' => '11:48',
                    'Asr' => '15:10',
                    'Maghrib' => '18:04',
                    'Isha' => '19:14',
                ],
            ],
        ];
    }

    public function test_fetches_from_upstream_and_caches(): void
    {
        Http::fake(['*api.aladhan.com*' => Http::response($this->aladhanPayload(), 200)]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/prayer-times/'.self::DATE.'?lat=11.5564&lng=104.9282')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['date', 'latitude', 'longitude', 'timings' => ['Fajr', 'Dhuhr', 'Asr', 'Maghrib', 'Isha']],
                'meta' => ['source'],
            ])
            // "(+07)" suffix must be normalised away.
            ->assertJsonPath('data.timings.Fajr', '04:32')
            ->assertJsonPath('meta.source', 'api');

        $this->assertDatabaseCount(PrayerTimeCache::class, 1);
    }

    public function test_second_request_is_served_from_cache_without_new_http_call(): void
    {
        Http::fake(['*api.aladhan.com*' => Http::response($this->aladhanPayload(), 200)]);

        Sanctum::actingAs(User::factory()->create());

        $url = '/api/v1/prayer-times/'.self::DATE.'?lat=11.5564&lng=104.9282';

        $this->getJson($url)->assertOk()->assertJsonPath('meta.source', 'api');
        $this->getJson($url)->assertOk()->assertJsonPath('meta.source', 'cache');

        // Exactly one upstream call for the day.
        Http::assertSentCount(1);
        $this->assertDatabaseCount(PrayerTimeCache::class, 1);
    }

    public function test_nearby_users_share_public_calculation_cache_without_sharing_user_rows(): void
    {
        Http::fake(['*api.aladhan.com*' => Http::response($this->aladhanPayload(), 200)]);

        $first = User::factory()->create();
        $second = User::factory()->create();
        $url = '/api/v1/prayer-times/'.self::DATE.'?lat=11.5564&lng=104.9282';

        Sanctum::actingAs($first);
        $this->getJson($url)->assertOk()->assertJsonPath('meta.source', 'api');
        Sanctum::actingAs($second);
        $this->getJson($url)->assertOk()->assertJsonPath('meta.source', 'shared-cache');

        Http::assertSentCount(1);
        $this->assertDatabaseCount(PrayerTimeCache::class, 2);
        $this->assertDatabaseHas('prayer_time_cache', ['user_id' => $first->id]);
        $this->assertDatabaseHas('prayer_time_cache', ['user_id' => $second->id]);
    }

    public function test_upstream_failure_gracefully_returns_unavailable_source(): void
    {
        Http::fake(['*api.aladhan.com*' => Http::response([], 500)]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/prayer-times/'.self::DATE)
            ->assertOk()
            ->assertJsonPath('data.timings', null)
            ->assertJsonPath('meta.source', 'unavailable');
    }

    public function test_falls_back_to_configured_default_coordinates(): void
    {
        Http::fake(['*api.aladhan.com*' => Http::response($this->aladhanPayload(), 200)]);

        Sanctum::actingAs(User::factory()->create());

        // No lat/lng supplied → server defaults (Phnom Penh) are used.
        $this->getJson('/api/v1/prayer-times/'.self::DATE)
            ->assertOk()
            ->assertJsonPath('data.latitude', 11.5564);
    }

    public function test_invalid_latitude_returns_422(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/prayer-times/'.self::DATE.'?lat=999')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_coordinates_must_be_supplied_as_a_pair(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/prayer-times/'.self::DATE.'?lat=11.5564')
            ->assertStatus(422)
            ->assertJsonValidationErrors('lng', 'error.details');
    }

    public function test_invalid_upstream_minutes_gracefully_return_unavailable_source(): void
    {
        $payload = $this->aladhanPayload();
        $payload['data']['timings']['Fajr'] = '04:99';
        Http::fake(['*api.aladhan.com*' => Http::response($payload, 200)]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/prayer-times/'.self::DATE)
            ->assertOk()
            ->assertJsonPath('data.timings', null)
            ->assertJsonPath('meta.source', 'unavailable');
    }

    public function test_prayer_times_require_authentication(): void
    {
        $this->getJson('/api/v1/prayer-times/'.self::DATE)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }
}
