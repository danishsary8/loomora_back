<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('focus_sessions', function (Blueprint $table) {
            $table->softDeletesTz(precision: 3);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE focus_sessions IN ACCESS EXCLUSIVE MODE');
            }

            $deleted = DB::table('focus_sessions')->whereNotNull('deleted_at')->count();
            if ($deleted > 0) {
                throw new RuntimeException("Cannot roll back B-046: {$deleted} focus session(s) are deleted.");
            }

            Schema::table('focus_sessions', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        });
    }
};
