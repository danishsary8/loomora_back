<?php

namespace App\Services;

use App\Models\Todo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Repeating to-dos (B-020). The first check-off of a repeating to-do creates the next one
 * in the same transaction and links it in `next_todo_id`, so checking off again never
 * creates a second one.
 */
class RepeatTodoService
{
    public function checkOff(Todo $todo): void
    {
        DB::transaction(function () use ($todo) {
            $todo->markDone();

            if ($todo->repeat_rule !== null && $todo->next_todo_id === null) {
                $todo->next_todo_id = $this->createNext($todo)->id;
                $todo->save();
            }
        });
    }

    /**
     * The next matching date after the later of the old due date and the user's today.
     *
     * @param  array{type: string, days?: list<int>}  $rule
     */
    public static function nextDueDate(array $rule, CarbonImmutable $dueDate, CarbonImmutable $today): CarbonImmutable
    {
        $date = $dueDate->max($today)->addDay();

        if ($rule['type'] === Todo::REPEAT_WEEKLY) {
            while (! in_array(ScheduleService::weekday($date), $rule['days'], true)) {
                $date = $date->addDay();
            }
        }

        return $date;
    }

    private function createNext(Todo $todo): Todo
    {
        $today = CarbonImmutable::now($todo->user->timezone)->startOfDay();
        $dueDate = CarbonImmutable::parse($todo->due_date->toDateString());

        $next = $todo->replicate(['done_at', 'next_todo_id', 'remind_at', 'remind_dismissed_at', 'created_at', 'updated_at']);
        $next->due_date = Carbon::instance(self::nextDueDate($todo->repeat_rule, $dueDate, $today));
        $next->save();

        foreach ($todo->subtasks as $subtask) {
            $copy = $subtask->replicate(['done_at', 'updated_at']);
            $copy->parent_id = $next->id; // created_at is kept so the copies keep their order
            $copy->save();
        }

        return $next;
    }
}
