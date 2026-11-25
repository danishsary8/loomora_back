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
            $table->string('label', 200)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Narrowing would truncate or fail on longer labels, so refuse instead of losing data.
        $longer = DB::table('focus_sessions')->whereRaw('length(label) > 120')->count();
        if ($longer > 0) {
            throw new RuntimeException("Cannot roll back B-045: {$longer} focus session label(s) are longer than 120 characters.");
        }

        Schema::table('focus_sessions', function (Blueprint $table) {
            $table->string('label', 120)->nullable()->change();
        });
    }
};
