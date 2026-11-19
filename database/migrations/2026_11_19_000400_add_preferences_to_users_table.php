<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('time_format', User::TIME_FORMATS)->default(User::DEFAULT_TIME_FORMAT);
            $table->unsignedSmallInteger('week_starts_on')->default(User::DEFAULT_WEEK_STARTS_ON);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['time_format', 'week_starts_on']);
        });
    }
};
