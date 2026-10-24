# Loomora: Task Queue

The queue Claude Code works from. Take the first task with status `todo`, top to bottom.

Status values: `todo` | `doing` | `review` | `done` | `blocked`

Rules:
- One task is about 1 to 2 hours of work and touches one concern.
- One task = one branch = one PR.
- A `blocked` task must state a clear question (secret, billing, or destructive action needed).
- "Done when" items must be checkable by running something.

## Task template

```md
### T-000: <title>
- **Status:** todo
- **Steps:**
  1. ...
  2. ...
- **Done when:**
  - [ ] ...
  - [ ] ...
- **Files:** `path/one`, `path/two`
```

## Queue

_No tasks yet._

## Done

_None yet._
