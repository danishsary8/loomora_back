<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->integer('revision')->default(1);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table notes add constraint notes_revision_check check (revision >= 1)');
        }
    }

    /** Only the counter is dropped; no note content is lost. */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table notes drop constraint notes_revision_check');
        }

        Schema::table('notes', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
    }
};
