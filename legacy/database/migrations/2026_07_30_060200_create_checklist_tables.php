<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('day_group_id')->constrained('day_groups')->cascadeOnDelete();
            $table->string('label', 160);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['day_group_id', 'sort_order']);
        });
        Schema::create('checklist_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('checklist_item_id')->constrained('checklist_items')->cascadeOnDelete();
            $table->date('log_date');
            $table->boolean('is_checked')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'checklist_item_id', 'log_date']);
            $table->index(['user_id', 'log_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_logs');
        Schema::dropIfExists('checklist_items');
    }
};
