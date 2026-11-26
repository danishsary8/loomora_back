<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** B-056: pin, color and tags on notes (docs/02 R4 "Notes: tags, pin, color"). Existing notes stay unpinned, uncolored and untagged. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->boolean('pinned')->default(false);
            $table->string('color', 16)->nullable();
            $table->json('tags')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("alter table notes add constraint notes_color_check check (color is null or color in ('study', 'work', 'health', 'life', 'rest', 'other'))");
        }
    }

    /** Drops only the organization fields; title, body and attachments are kept. */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table notes drop constraint notes_color_check');
        }

        Schema::table('notes', function (Blueprint $table) {
            $table->dropColumn(['pinned', 'color', 'tags']);
        });
    }
};
