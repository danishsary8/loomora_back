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
