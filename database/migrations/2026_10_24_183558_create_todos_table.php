<?php

use App\Models\Todo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('todos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->enum('category', Todo::CATEGORIES);
            $table->date('due_date')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todos');
    }
};
