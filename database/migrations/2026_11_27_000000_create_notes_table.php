<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('schedule_item_id')->nullable()->constrained()->nullOnDelete();
            $table->date('local_date')->nullable();
            $table->string('title', 120)->nullable();
            $table->text('body')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'deleted_at', 'updated_at', 'id']);
            $table->index('schedule_item_id');
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE notes IN ACCESS EXCLUSIVE MODE');
            }

            if (DB::table('notes')->exists()) {
                throw new RuntimeException('Cannot roll back B-048 while the notes table contains data.');
            }

            Schema::dropIfExists('notes');
        });
    }
};
