<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('day_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('color', 7)->default('#0F766E');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'sort_order']);
        });
        Schema::create('day_group_weekdays', function (Blueprint $table): void {
            $table->foreignId('day_group_id')->constrained('day_groups')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->primary(['day_group_id', 'weekday']);
            $table->index('weekday');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_group_weekdays');
        Schema::dropIfExists('day_groups');
    }
};
