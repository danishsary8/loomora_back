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
| I6 | 2026-11-19 | `remind_at` is set with `POST`/`PATCH /todos` and must be ISO 8601 with an explicit offset (`Z` or `+07:00`); it is stored and returned in UTC. A different `remind_at` (edit or snooze) clears the dismissal, enforced in the model so every path follows it. Dismiss keeps `remind_at` and the first dismissal time. Snoozing a subtask is a 422 like setting its reminder. | A time without an offset would be silently read as UTC and fire hours off for Cambodian users. One rule in the model avoids a path that forgets to clear the dismissal. | B-021 |
| I7 | 2026-11-19 | Reminder endpoints answer 404 for another user's to-do (policy `remind`, `denyAsNotFound`), while the older to-do endpoints keep 403. The next to-do of a repeat keeps its reminder: `remind_at` moves by the same number of days as the due date (a whole number of 24 h steps in UTC, so a daylight-saving change in the user's zone shifts the local reminder time by an hour), is null when the old to-do had none, and `remind_dismissed_at` is not copied (changed by B-026 after Q18a). The Postgres connection sets `timezone: UTC` so `timestamptz` columns read the app's UTC values correctly. | The task asks for 404. Copying an absolute time would make the next to-do's reminder fire at once (it is already in the past); see Q18. | B-021 |
| I8 | 2026-11-19 | To-dos are soft-deleted. Deleting a parent stamps the parent and its live subtasks with the same `deleted_at`; a restore (within 7 days, `deleted_at` later than now minus 7 days) brings back the parent and exactly the subtasks with that time, so a subtask deleted on its own earlier stays deleted. Deleting a to-do clears `next_todo_id` on the to-do that pointed at it (as the old foreign key did). Restoring a to-do that is not deleted returns it (200) so a double tap on Undo is harmless. A deleted to-do does not count against the 500 limit; a restore is not checked against it. Import skips an id the user already deleted and rejects another user's deleted id. | Keeps the I5 rule, makes Undo idempotent, and avoids a 500 from a primary key clash. | B-022 |
