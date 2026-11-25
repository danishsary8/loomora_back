# B-043: R3 Focus Session API Contract and Persistence

**Status:** contract approved by the planner with amendments (2026-11-23). The design proposal merged in [PR #46](https://github.com/danishsary8/loomora_back/pull/46) (docs only). The implementation is on `feat/B-043-focus-sessions-implementation` in [PR #47](https://github.com/danishsary8/loomora_back/pull/47). `docs/openapi.yaml` is the exact contract. This document records the decisions and the reasons for them.

**Base:** `v2-rebuild` at `c9a7acf0e03a0a3ee5f60cc38198634fd0f58e3d`.

## Why

R3 Focus (`docs/02-feature-list.md`) has these Musts: a Focus timer linked to a to-do or schedule item, countdown presets (25, 50 and 90 min plus custom), a stopwatch with laps to 1/100 s, and time tracking from sessions. The roadmap adds break cycles, ending early without losing time, and keeping the timer across navigation and reload. The backend stores each finished session exactly once, so history and later analytics read one source of truth. This avoids DayCraft's double counting (research D3).

## Approved decisions

| # | Decision |
|---|---|
| Q-B043-0 | **Option A.** The frontend owns the running timer, the countdown, the stopwatch and reload recovery. The backend stores one immutable, finished session. There is no server-side live timer and no cross-device sync. |
| Q-B043-1 | `focus_ms` is the sum of `active_ms` over `focus` segments. `break_ms` is the sum over break segments. `paused_ms` is the wall time minus both. Pauses and breaks never count toward tracked Focus time. Segments must be ordered and non-overlapping, and must stay inside the session. |
| Q-B043-2 | A new UUID returns 201. An identical replay returns 200 with the stored resource. A changed payload for an existing UUID, or another account's UUID, returns 409 with the same message for both, and the stored row is unchanged. Races are resolved in the database. |
| Q-B043-3 | **Amended.** There is no 60-second minimum. Any session with `focus_ms > 0` is saved with its exact milliseconds. Zero Focus time is a 422. |
| Q-B043-4 | A soft-deleted owned to-do keeps its link. Deleting a schedule item sets `schedule_item_id` to null, and the session and its `label` stay. An unknown link or another account's link is a 422 with one message. No cascade from planner items to sessions. |
| Q-B043-5 | **Deferred.** `POST /import` is unchanged. There is no account-level cap and no import batch limit. Guest Focus history stays on the device until a separately approved import contract (coordinate with B-044). |
| Q-B043-6 | GET of an unknown session or another user's session returns 404. All routes require Sanctum, and the API never accepts `user_id`. |

Other approved rules:
- The table is `focus_sessions`, because Laravel already uses `sessions`.
- Durations and laps are integer milliseconds.
- Laps are cumulative active milliseconds and strictly increasing.
- `local_date` is the local date of `started_at` in the account's IANA time zone, so a session that crosses midnight belongs to the day it started.
- The response envelopes are `{data}` and `{data: [...]}`.

Out of scope for B-043:
- timer-history UI, deletion and undo
- sound settings
- analytics
- preset management
- cross-device control
- custom-duration upper bounds and other research-only rules

## Endpoints

All routes are under `/api/v1` with `auth:sanctum` and `throttle:api`. The path id must be a UUID, otherwise 404.

| Method | Path | Success | Errors |
|---|---|---|---|
| POST | `/focus-sessions` | 201 created, or 200 for an identical replay; `{data: FocusSession}` | 401, 409, 422, 429 |
| GET | `/focus-sessions/{focusSession}` | 200 `{data: FocusSession}` | 401, 404 |
| GET | `/focus-sessions?date=YYYY-MM-DD` | 200 `{data: [FocusSession]}`: that local day, oldest `started_at` first | 401, 422 |

Request and response examples are in `docs/openapi.yaml` (`recordFocusSession`, `FocusSessionResponse`).

## Validation (as implemented)

| Field | Rule |
|---|---|
| `id` | required UUID. Compared case-insensitively and stored in lower case. |
| `mode` | `countdown` \| `stopwatch` |
| `end_reason` | `completed` \| `ended_early` |
| `started_at`, `ended_at`, `segments[].started_at`/`ended_at` | ISO 8601 with an explicit offset. Instants are compared in UTC at millisecond precision, and finer digits are dropped. `ended_at >= started_at`. Never compared with the server clock (see Clock policy). |
| `planned_focus_ms` | Countdown: required, integer ≥ 1 (no upper bound). Stopwatch: null or absent. |
| `segments` | 1 to 200. Each segment has exactly `type`, `started_at`, `ended_at` and `active_ms`. Segments are in order and non-overlapping, inside `[started_at, ended_at]`, with `0 <= active_ms <= segment wall time`. Break types are allowed in countdown only. The total `focus_ms` must be > 0. |
| `laps` | Stopwatch only: null, absent, or up to 1000 integers ≥ 1, strictly increasing, each ≤ `focus_ms`. An empty list is stored as null. |
| `todo_id` / `schedule_item_id` | Nullable UUIDs. At most one is set. Ownership is checked by the service (see below). |
| `label` | Nullable, up to 200 characters (trimmed; an empty string becomes null). Amended by B-045 (was 120). |

Over-bound payloads (201 segments, 1001 laps, a 201-character label; amended by B-045, was 121) are a 422 on that field ("must not have more than …"). Nothing is ever truncated.

## Clock policy (approved by the planner, 2026-11-23)

The proposal rejected an `ended_at` more than 5 minutes ahead of the server clock. A fast device clock could then make a finished offline session fail every retry until the server caught up, and the client could not fix the payload. That rule was removed. It is not in the authoritative docs, and no other Loomora endpoint compares client timestamps with the server clock (import takes `done_at` and `created_at` as sent).

- The server never rejects a finished session because of its age or because it is ahead of the server clock. Only internal consistency is validated: order, bounds, segments and totals.
- Times are stored as the device recorded them. `local_date` comes from that `started_at`, the same as every other client timestamp in the app.
- Tests: a session two hours ahead of the server clock and one from more than a year ago are both saved with 201.

**Exact segment durations (approved by the planner, 2026-11-23):** the proposal allowed `active_ms` to exceed its segment's wall time by 1 s. The implementation has no tolerance. This keeps `focus_ms + break_ms + paused_ms` exactly equal to the wall time, and `paused_ms` never negative, which is the "internally consistent totals" rule. Clients clamp `active_ms` to the segment's own timestamps.

## Idempotency and concurrency

1. The request is validated (shape and internal consistency) and normalised to UTC milliseconds and lower-case UUIDs. Its SHA-256 fingerprint is stored as `payload_hash`. Equal instants written differently (`Z` or `+07:00`) are identical.
2. In one transaction, an existing row with the id is compared first, including deleted rows (B-046). The same owner and fingerprint return 200 with the stored row; anything else is a 409. This runs **before** the link check, so a retry of a stored session still returns 200 after its schedule item was deleted. An identical replay of a deleted session still returns 200 and leaves it deleted (D3).
3. Otherwise the linked to-do (including soft-deleted) or schedule item is read `FOR SHARE` for the current user, so it cannot be deleted before the insert. A missing link is a 422 with `errors.todo_id` or `errors.schedule_item_id` set to "The selected item was not found."
4. The insert uses `INSERT … ON CONFLICT DO NOTHING` on the primary key. A concurrent request with the same id waits for the winner's commit, inserts nothing, re-reads the winning row and applies step 2 (200 or 409). The PostgreSQL concurrency test drives this real race.

## Database

Migration `2026_11_23_000000_create_focus_sessions_table`:

| Column | Type |
|---|---|
| `id` | uuid primary key (client) |
| `user_id` | uuid FK `users`, cascade on delete (docs/08 rule 10) |
| `mode` | string(16) |
| `end_reason` | string(16) |
| `started_at`, `ended_at` | timestamptz(3) |
| `local_date` | date |
| `planned_focus_ms` | bigint, nullable |
| `focus_ms`, `break_ms`, `paused_ms` | bigint |
| `todo_id` | uuid nullable, FK `todos`, null on delete |
| `schedule_item_id` | uuid nullable, FK `schedule_items`, null on delete |
| `label` | string(200), nullable (widened from 120 by B-045) |
| `segments` | jsonb |
| `laps` | jsonb, nullable |
| `payload_hash` | char(64) |
| `created_at`, `updated_at` | timestamps |

There is one index, `(user_id, local_date, started_at)`, for the day list. PostgreSQL check constraints enforce:
- the allowed `mode` and `end_reason` values
- `ended_at >= started_at`
- `focus_ms > 0`, `break_ms >= 0` and `paused_ms >= 0`
- at most one link

B-046 adds a nullable millisecond-precision deleted_at. Durations are `bigint` because no upper bound was approved.

## Frontend handoff (F-R3-01)

- **Session id:** generate it when the timer starts and keep it in the persisted timer state. Every retry reuses it.
- **When to send:** `POST /focus-sessions` once when a session finishes (completed or ended early) with `focus_ms > 0`. Do not send a session with zero Focus time.
- **Responses:**
  - 201 or 200: saved. Clear the local draft.
  - Network error, 5xx or 429: keep the draft and retry later with the same id and payload.
  - 409: do not retry; keep the draft for the user and report it.
  - 422: fix the payload (see below for link errors).
- **Units:** integer milliseconds everywhere. Timestamps carry an explicit offset. `segments[].active_ms` excludes pauses and is no longer than the segment's own timestamps. Laps are cumulative active milliseconds.
- **Clock skew:** never discard a draft because of the device clock. No response depends on the server time.
- **Deleted schedule item while offline:** if a pending session gets 422 with `errors.schedule_item_id` (or `errors.todo_id`), it was never stored. Keep the local draft. Resend it with the **same id**, the link set to null and the `label` kept: this returns 201. Never drop the draft on this error. If the original request had in fact been stored, the original payload returns 200 instead, so retry the unchanged payload first.
- **Guests:** keep Focus history locally; there is no import yet (Q-B043-5).
- **Status:** Codex's current F-R3-02A (pure timer calculations) must not call these endpoints until the planner opens F-R3-01.

## Future policy questions (not part of B-043)

- The guest Focus history import contract, its capacity and its interaction with B-044's chunked-import policy.
- Session deletion and undo, paginated history and search (timer history is a Should).
- Focus presets storage and sound settings.
- Whether R4 analytics needs further indexes or aggregates.

### B-046 amendment

DELETE /focus-sessions/{focusSession} returns 204, including on repeat. POST /focus-sessions/{focusSession}/restore returns the same session for an active row or a deletion less than seven days old, and 410 when the undo window expires. Both operations return 404 for unknown or foreign IDs. Active list and show reads hide deleted sessions. Account deletion removes all sessions through the user foreign key. Focus deletes keep the original UUID and the recording replay contract.
