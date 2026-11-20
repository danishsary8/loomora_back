<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_exchange_digest', 64)->nullable()->unique();
            $table->timestamp('google_exchange_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_exchange_digest']);
            $table->dropColumn(['google_exchange_digest', 'google_exchange_expires_at']);
        });
    }
};
