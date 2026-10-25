<?php

use App\Models\ScheduleItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->enum('category', ScheduleItem::CATEGORIES);
            $table->time('start_time');
            $table->time('end_time');
            // Weekdays the item repeats on, 0 = Monday ... 6 = Sunday.
            $table->jsonb('days');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_items');
    }
};
