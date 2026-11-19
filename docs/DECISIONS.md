# Loomora: Decisions Log

Decisions made during implementation, with the reason. Product decisions live in `01-product-brief.md` (D1 onward) and setup decisions in `07-project-decisions.md` (S1 onward). Implementation decisions here use the prefix `I`.

## Template

| # | Date | Decision | Reason | Task |
|---|---|---|---|---|
| I0 | YYYY-MM-DD | ... | ... | T-000 |

## Decisions

| # | Date | Decision | Reason | Task |
|---|---|---|---|---|
| I1 | 2026-11-19 | Subtask check-offs count for the streak like any to-do (a subtask is a row in `todos` with its own `done_at`). | Task B-019 says so; it rewards progress on big to-dos before the whole thing is done. | B-019 |
| I2 | 2026-11-19 | A subtask has no `due_date` of its own (422) and takes its parent's category unless one is sent. `parent_id` is set only on create. | The Today list shows top-level to-dos only, so a subtask date would never show and would add "planned" days to the streak. A fixed parent avoids moving a to-do that has subtasks under another. | B-019 |
| I3 | 2026-11-19 | Checking off a parent gives its open subtasks the parent's `done_at`; subtasks already done keep their own time. Deleting a parent deletes its subtasks (foreign key cascade until B-022 adds soft delete). | One transaction, one timestamp; earlier completion times stay true for the streak. | B-019 |
| I4 | 2026-11-19 | Repeat rule: `{type: daily}` or `{type: weekly, days}` with days 0 = Monday to 6 = Sunday (same as schedule items), stored sorted. The first check-off creates the next to-do in the same transaction; its due date is the next matching date after the later of the old due date and today in the user's time zone. `next_todo_id` makes it happen once: unchecking and checking again creates nothing. | Task B-020. Using the later date means an overdue repeat does not create a to-do that is already overdue. | B-020 |
| I5 | 2026-11-19 | The next to-do copies title, category, priority, notes, rule and the subtasks (undone, keeping their `created_at` so their order stays). It is created even if the user is at the 500 to-dos limit. Deleting the next to-do sets `next_todo_id` back to null. Editing a to-do never changes a next one already created. | A check-off must never fail because of the limit; see Q17. | B-020 |
