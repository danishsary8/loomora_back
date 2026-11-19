<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->timestampTz('remind_at')->nullable();
            $table->timestampTz('remind_dismissed_at')->nullable();
            $table->index(['user_id', 'remind_at']);
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'remind_at']);
            $table->dropColumn(['remind_at', 'remind_dismissed_at']);
        });
    }
};
