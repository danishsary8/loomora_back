<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timeline_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('day_group_id')->constrained('day_groups')->cascadeOnDelete();
            $table->time('start_time');
            $table->time('end_time');
            $table->string('category', 24)->default('Life');
            $table->string('description', 180);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['day_group_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeline_entries');
    }
};
