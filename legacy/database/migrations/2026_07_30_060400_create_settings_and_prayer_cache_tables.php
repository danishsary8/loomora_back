<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('minutes_before')->default(5);
            $table->timestamps();
        });

        Schema::create('prayer_time_cache', function (Blueprint $table): void {
            $table->id();

            // NULL user_id allows a shared/global cache row for a coordinate set.
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();

            $table->date('cache_date');
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);

            $table->time('fajr');
            $table->time('dhuhr');
            $table->time('asr');
            $table->time('maghrib');
            $table->time('isha');

            $table->timestamps();

            $table->index('cache_date');
            $table->index(['user_id', 'cache_date'], 'prayer_cache_user_date_idx');

            // One cached row per user per calendar day.
            $table->unique(['user_id', 'cache_date'], 'prayer_cache_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prayer_time_cache');
        Schema::dropIfExists('notification_settings');
    }
};
