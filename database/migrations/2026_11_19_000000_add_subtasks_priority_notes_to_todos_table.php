<?php

use App\Models\Todo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->text('notes')->nullable();
            $table->enum('priority', Todo::PRIORITIES)->default(Todo::DEFAULT_PRIORITY);
            $table->foreignUuid('parent_id')->nullable()->constrained('todos')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['notes', 'priority']);
        });
    }
};
