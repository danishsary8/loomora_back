<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->jsonb('repeat_rule')->nullable();
            $table->foreignUuid('next_todo_id')->nullable()->constrained('todos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('next_todo_id');
            $table->dropColumn('repeat_rule');
        });
    }
};
