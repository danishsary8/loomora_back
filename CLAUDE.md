# CLAUDE.md: Rules for working on Loomora

## Source of truth
- `docs/` is the source of truth.
- If code and docs disagree, stop and write the question in `docs/PROGRESS.md` (Open questions).
- Never invent features outside `docs/02-feature-list.md`.

## Every session
1. Read `docs/TASKS.md` and `docs/PROGRESS.md` first.
2. Take the first task with status `todo` and set it to `doing`.
3. At the end, append a note to `docs/PROGRESS.md` (template inside the file).

## Workflow
- One task = one branch = one PR.
- Commit messages follow Conventional Commits (`feat:`, `fix:`, `chore:`, `docs:`, `test:`, `refactor:`).
- Before coding: write a 3 to 6 bullet plan.
- After coding: note how it was verified (tests run, output).
- If a task needs a secret, billing, or a destructive action, mark it `blocked` with a clear question and move to the next task.

## API contract
- `docs/openapi.yaml` is the contract between the back and front repos. Any change to an endpoint, request or response updates it in the same PR.
- Check it with `npx @redocly/cli lint docs/openapi.yaml` (must be valid).
- Current response shapes: register and login return `{token, expires_at, user}`; `/me` and single resources return `{data: ...}`; lists return `{data: [...]}`.

## UI
- Mobile-first: design for 375 px wide, then tablet and desktop.
- Neo-brutalism on cream `#F5EDE6`: thick black borders, hard offset shadows, press-down states, flat category colors. One theme only.
- Use the `ui-ux-pro-max` skill for any UI work.
- All user-facing text goes through a translation system (English now, Khmer later). No hard-coded strings in the UI.

## Safety
- Never commit secrets. Keep `.env.example` up to date with empty values.
- Never force-push. Never delete data, branches, or files that hold data.
