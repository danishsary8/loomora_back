<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_deletion_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('personal_access_token_id');
            $table->string('google_subject');
            $table->string('purpose');
            $table->char('browser_challenge', 43);
            $table->char('state_digest', 64)->nullable();
            $table->char('nonce_digest', 64)->nullable();
            $table->text('pkce_verifier')->nullable();
            $table->string('status');
            $table->timestamp('created_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('confirmation_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_deletion_challenges');
    }
};
