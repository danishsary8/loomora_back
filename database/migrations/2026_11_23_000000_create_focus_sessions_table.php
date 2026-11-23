<?php

use App\Models\FocusSession;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('focus_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 16);
            $table->string('end_reason', 16);
            $table->timestampTz('started_at', 3);
            $table->timestampTz('ended_at', 3);
            $table->date('local_date');
            $table->bigInteger('planned_focus_ms')->nullable();
            $table->bigInteger('focus_ms');
            $table->bigInteger('break_ms');
            $table->bigInteger('paused_ms');
            $table->foreignUuid('todo_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('schedule_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', FocusSession::MAX_LABEL_LENGTH)->nullable();
            $table->jsonb('segments');
            $table->jsonb('laps')->nullable();
            $table->char('payload_hash', 64);
            $table->timestamps();

            $table->index(['user_id', 'local_date', 'started_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                alter table focus_sessions
                    add constraint focus_sessions_mode_check check (mode in ('countdown', 'stopwatch')),
                    add constraint focus_sessions_end_reason_check check (end_reason in ('completed', 'ended_early')),
                    add constraint focus_sessions_time_order_check check (ended_at >= started_at),
                    add constraint focus_sessions_durations_check check (focus_ms > 0 and break_ms >= 0 and paused_ms >= 0),
                    add constraint focus_sessions_one_link_check check (todo_id is null or schedule_item_id is null)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('focus_sessions');
    }
};
