# Loomora: Roadmap and Automation Setup (v0.1)

## 1. Phases (each phase = small tasks, each task = one branch + one PR)
| Phase | Name | Owner | Output |
|---|---|---|---|
| P0 | One-time setup | Danish (about 15 min) | Repo, docs in repo, automation switched on |
| P1 | Finish the plan | Planner chat | A6 data model + rules, A7 API design, A8 tech decisions + repo structure |
| P2 | Design | Planner chat + Claude Code (ui-ux-pro-max) | B1 style, B2 tokens, B3 wireframes (mobile first), B4 components, B5 coded style guide page |
| P3 | Foundation (C0) | Claude Code | Scaffold frontend and backend, lint, tests, CI, database, first deploy, health check |
| P4 | Guest and account (C1) | Claude Code | Guest storage, email signup/login, Google login, guest-to-account migration |
| P5 | To-dos (C2) | Claude Code | To-do model, API, UI, check-off, edit, delete, overdue carry-over |
| P6 | Schedule (C3) | Claude Code | Schedule model with repeat days and one-day exceptions, Week view, add/edit sheets, Copy day |
| P7 | Today screen (C4) | Claude Code | Now/Next strip, progress ring, streak, stamp animation, empty states |
| P8 | R1 polish and release | Claude Code + planner | PWA install, feedback button, error/offline states, a11y pass, README with screenshots, deploy, tag v1.0 |
| P9 | R2 (repeat, subtasks, reminders) | Claude Code | per docs/02 |
| P10 | R3 (focus timer, countdown, stopwatch, history) | Claude Code | per docs/02 |
| P11 | R4 (notes, analytics, weekly recap, Plan vs Reality) | Claude Code | per docs/02 |
| P12 | Khmer, performance, final polish | Claude Code | translations, Lighthouse targets |

Task size rule: one task is about 1 to 2 hours of work, touches one concern, and has a "done when" list that can be checked by running something.

## 2. Roles
- **Planner chat (this chat):** writes tasks, reviews PRs, decides what is next, updates docs.
- **Claude Code:** implements one task at a time, tests it, opens a PR, writes a note.
- **Danish:** supplies secrets, approves risky actions, can look at the status file any time.

## 3. Files that make automation work (created in P0)
- `docs/TASKS.md`: the queue. Each task: id, title, status (todo / doing / review / done / blocked), steps, done-when, files likely touched.
- `docs/PROGRESS.md`: Claude Code appends after every task: what changed, how it was verified, problems, questions.
- `docs/DECISIONS.md`: any decision made along the way, with the reason.
- `CLAUDE.md`: the rules (small steps, no invented features, conventional commits, tests before PR, never touch secrets).

## 4. The loop (runs without you watching)
1. Claude Code session starts (scheduled run) and reads `CLAUDE.md`, `docs/TASKS.md`, `docs/PROGRESS.md`.
2. It takes the first `todo` task, creates a branch, implements, runs lint and tests.
3. It opens a PR, marks the task `review`, appends to `docs/PROGRESS.md`.
4. The planner chat wakes (timed reminder), reads the PR and notes, reviews, then either approves and merges, or writes a fix task at the top of the queue.
5. The planner writes or refines the next tasks if the queue is short.
6. If usage runs out, the session stops. The next scheduled run picks up from the files. Nothing depends on chat memory.
7. If anything needs a human (secrets, billing, deleting data), the task becomes `blocked` with a clear question, and the loop moves to another task.

## 5. Safety rules
- Secrets only in `.env` and host dashboards, never in git or chat.
- No force-push, no deleting branches or data, no production database changes without a migration file.
- Merges to `main` only after tests pass.
- Anything uncertain: mark `blocked` and write the question in `docs/PROGRESS.md`.

## 6. Danish's one-time setup (P0)
1. Create a private GitHub repo `loomora` (empty).
2. In Claude Code on the web, connect that repo and run Prompt 00 with the 6 docs attached.
3. Tell the planner chat the repo name so it can be attached here.
4. Create Render and Neon accounts if not done (secrets are added by you later in P3).
5. Say "start" in the planner chat.
