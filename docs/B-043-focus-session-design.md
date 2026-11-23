# B-043: R3 Focus Session API Contract and Persistence (design proposal)

**Status:** proposal, waiting for planner approval. Nothing in this document is implemented and `docs/openapi.yaml` is unchanged. The decisions marked **Needs approval** must be answered before any migration, route or OpenAPI change lands.

**Base:** `v2-rebuild` at `c9a7acf0e03a0a3ee5f60cc38198634fd0f58e3d`.

## Why

R3 Focus (`docs/02-feature-list.md`) has these Musts: a Focus timer linked to a to-do or schedule item, countdown presets (25, 50 and 90 min plus custom), a stopwatch with laps to 1/100 s, and time tracking from sessions. The roadmap also commits to break cycles, ending early without losing time, and keeping the timer across navigation and reload. Timer history and sound are Shoulds. R4 analytics and Plan vs Reality are out of scope.

The backend's job is to store each finished session once, so later history and analytics read one source of truth. This fixes DayCraft's double counting (research D3). The running timer engine, reload survival and UI belong to the frontend: the research (M1, D15) keeps the running state in the browser with a wall-clock `endAt`.

## What the docs already settle, and what they do not

| Topic | Source | Status |
|---|---|---|
| Client-generated UUID primary keys, UTC storage, local dates in `users.timezone` | docs/08 | Settled |
| `/api/v1`, Sanctum bearer, `{data: ...}` / `{data: [...]}`, one error shape, 120/min throttle, never accept `user_id` | docs/09, CLAUDE.md | Settled |
| Account deletion removes every row | docs/08 rule 10 | Settled |
| Streak counts check-offs only, so focus sessions do not count | docs/08 rule 8 | Settled |
| Import is idempotent by id: an existing id is skipped, not overwritten | docs/08 rule 9, `ImportService` | Settled for existing tables |
| Timer endpoints | docs/09: "Later releases add endpoints (... timers ...) in their own docs" | **Not settled.** This document is the proposal. |
| One `sessions` table with `kind`, segments, laps, `active_seconds`, a 60 s minimum | `docs/research/` DC-30, D3, D12 | Research only, not approved policy |

## Facts from the code that shape the design

1. **`sessions` is taken.** Laravel's web-session table `sessions` already exists (`0001_01_01_000000_create_users_table.php`), so the research name cannot be used. Proposed table `focus_sessions`, model `FocusSession`.
2. **To-dos are soft-deleted (DECISIONS I8); schedule items are hard-deleted.** Deleting a schedule item removes its row. A focus session that points at it needs an explicit foreign-key rule, and `cascadeOnDelete` would destroy tracked time.
3. **Ownership responses vary.** The older to-do endpoints return 403 for another user's row (Q7c). Reminder endpoints and `daily_intentions` return 404 (I7). Import treats a foreign id as a row-level 422.
4. **Import skips an existing id silently** and never overwrites it. A direct endpoint can choose to be stricter (see Q-B043-2).
5. **Import capacity is open.** The frontend sends chunked imports, and the per-request atomicity question is open in PROGRESS. The frontend has a provisional import follow-up that the planner proposes to renumber B-044.

## Approaches considered

| | A. Record a finished session (recommended) | B. Server-side lifecycle | C. Finished session plus later edits |
|---|---|---|---|
| Shape | One `POST` per finished session (completed or ended early). The browser holds the running state. | `POST` on start, then `PATCH` for pause, resume, phase and lap, then finish. | As in A, plus `PATCH` for label or link afterwards. |
| Fits offline/guest PWA | Yes. Queue locally, send once, safe retry by UUID. | Poor. Every tap needs the network, or the server state falls behind. | Yes |
| Reload survival | Frontend (research M1/D15), unaffected | Could also resume across devices, which is not a requirement | Frontend |
| Idempotency | Simple: one immutable record per UUID | Hard: ordered partial updates and conflicts | Edits need version or conflict rules |
| Trust | Server validates internal consistency of a closed record | Server clock would be authoritative | As A |
| Size | Smallest | Largest | A plus extra rules |

**Recommendation: A.** It satisfies every R3 Must, matches guest mode (sessions recorded offline import the same way), and keeps idempotency exact. B solves cross-device resume, which nobody asked for. C adds edit semantics that history (a Should) can introduce later if it needs them. **Needs approval (Q-B043-0).**

## Proposed contract (Approach A)

All routes are under `/api/v1`, inside the existing `auth:sanctum` + `throttle:api` group, with `whereUuid('focusSession')`.

| Method | Path | Purpose | Success |
|---|---|---|---|
| POST | `/focus-sessions` | Record one finished session (idempotent by `id`) | `201 {data}`. Identical replay: `200 {data}` |
| GET | `/focus-sessions/{focusSession}` | Read one of the user's sessions | `200 {data}` |
| GET | `/focus-sessions?date=YYYY-MM-DD` | The user's sessions for one local day (time tracking), oldest first | `200 {data: [...]}` |

Not in B-043: deletion, paginated history, search, presets and stats. History and presets are Shoulds (research DC-31, DC-38), and stats belong to R4.

### Request: `POST /focus-sessions`

```json
{
  "id": "6f1c2a8e-3b4d-4e5f-8a9b-0c1d2e3f4a5b",
  "mode": "countdown",
  "started_at": "2026-11-23T02:00:00.000Z",
  "ended_at": "2026-11-23T03:02:30.000Z",
  "end_reason": "completed",
  "planned_focus_ms": 3000000,
  "todo_id": "1a2b3c4d-0000-4000-8000-000000000001",
  "schedule_item_id": null,
  "label": "Read chapter 4",
  "segments": [
    {"type": "focus", "started_at": "2026-11-23T02:00:00.000Z", "ended_at": "2026-11-23T02:27:00.000Z", "active_ms": 1500000},
    {"type": "short_break", "started_at": "2026-11-23T02:27:00.000Z", "ended_at": "2026-11-23T02:32:00.000Z", "active_ms": 300000},
    {"type": "focus", "started_at": "2026-11-23T02:32:00.000Z", "ended_at": "2026-11-23T03:02:30.000Z", "active_ms": 1500000}
  ],
  "laps": null
}
```

A stopwatch example uses `"mode": "stopwatch"`, `"planned_focus_ms": null`, a single `focus` segment, and `"laps": [61230, 125470, 190010]`, which are cumulative milliseconds from the start of the active time.

### Fields and units

| Field | Type | Rule (proposal) |
|---|---|---|
| `id` | uuid | Required, client-generated. Idempotency key. |
| `mode` | `countdown` \| `stopwatch` | Required. Presets and custom times are client choices that become `planned_focus_ms`. |
| `started_at`, `ended_at` | ISO 8601 with an explicit offset (as I6), stored UTC with millisecond precision | `ended_at >= started_at`, total at most 24 h, `ended_at` no more than 5 min ahead of the server clock |
| `end_reason` | `completed` \| `ended_early` | Required. "Ending early keeps elapsed time" means an `ended_early` session is stored with its real durations. |
| `planned_focus_ms` | int ms \| null | Countdown: required, the length of one focus phase, 60 000 to 10 800 000 (1 to 180 min, the research preset bound). Stopwatch: must be null. |
| `segments[]` | array, 1 to 200 | Ordered, non-overlapping, inside `[started_at, ended_at]`. `type` is `focus`, `short_break` or `long_break`. Breaks are countdown-only. `active_ms` is at most the segment's wall time (+1 s tolerance); the gap is paused time. |
| `laps` | int ms[] \| null | Stopwatch only, at most 1000, strictly increasing, each at most the focus total. Stored in ms; the client shows 1/100 s. |
| `todo_id`, `schedule_item_id` | uuid \| null | At most one is set. It must be the user's own row; see Q-B043-4 for deleted rows. |
| `label` | string 1 to 120 \| null | Snapshot of the item title at start, so history still reads after the link goes away. |

Durations are always integer **milliseconds** in the API and the database, because laps need sub-second precision and one unit avoids mixed seconds and minutes. `bigint` is not needed: 24 h is 86 400 000 ms, which fits in `integer`.

### Response: `{data: FocusSession}`

The request fields plus these values, which the server computes and never accepts from the client:

```json
{
  "data": {
    "id": "6f1c2a8e-3b4d-4e5f-8a9b-0c1d2e3f4a5b",
    "mode": "countdown",
    "started_at": "2026-11-23T02:00:00.000Z",
    "ended_at": "2026-11-23T03:02:30.000Z",
    "end_reason": "completed",
    "local_date": "2026-11-23",
    "planned_focus_ms": 3000000,
    "focus_ms": 3000000,
    "break_ms": 300000,
    "paused_ms": 450000,
    "todo_id": "1a2b3c4d-0000-4000-8000-000000000001",
    "schedule_item_id": null,
    "label": "Read chapter 4",
    "segments": ["..."],
    "laps": null,
    "created_at": "2026-11-23T03:02:31.000000Z"
  }
}
```

- `focus_ms` is the sum of `active_ms` over `focus` segments. This is the **tracked Focus time** if Q-B043-1 is approved as proposed.
- `break_ms` is the sum of `active_ms` over break segments.
- `paused_ms` is the wall time minus `focus_ms` and `break_ms`.
- `local_date` comes from `started_at` in `users.timezone` via `App\Support\LocalDate`. A session that crosses midnight belongs to the day it started.

### Errors

The existing shape is `{message, errors?}`.

| Status | When |
|---|---|
| 401 | No or expired token |
| 404 | `GET` of an unknown session or another user's session (no existence leak, following I7) |
| 409 | `POST` with an `id` that already exists with a **different** payload, or that belongs to another account (same message for both, no leak). See Q-B043-2. |
| 422 | Any field rule above; an unknown or other-user `todo_id`/`schedule_item_id`; both links set; a session below the minimum duration (Q-B043-3); `?date` missing or invalid |
| 429 | Existing `throttle:api` |

### Idempotency

The `POST` runs in one transaction. It locks or inserts by primary key, and a unique-violation race is caught and re-read, so two concurrent retries produce one row: one 201 and one 200. "Identical" compares the normalised request fields. The server-computed fields and `created_at` are excluded.

## Database (proposal)

`focus_sessions`:

| Column | Type |
|---|---|
| `id` | uuid primary key (client) |
| `user_id` | uuid FK `users`, cascade on delete (docs/08 rule 10) |
| `mode` | string(16) |
| `end_reason` | string(16) |
| `started_at`, `ended_at` | timestampTz(3) |
| `local_date` | date |
| `planned_focus_ms` | integer, nullable |
| `focus_ms`, `break_ms`, `paused_ms` | integer |
| `todo_id` | uuid nullable, FK `todos`, **null on delete** |
| `schedule_item_id` | uuid nullable, FK `schedule_items`, **null on delete** |
| `label` | string(120), nullable |
| `segments` | jsonb (json on SQLite) |
| `laps` | jsonb, nullable |
| `created_at`, `updated_at` | timestamps |

Indexes are `(user_id, local_date)` and `(user_id, started_at)`.

Segments and laps are stored as validated JSON rather than child tables. They are only ever read with their session, they are bounded (200 and 1000), and the immutable record makes per-row edits unnecessary. There are no soft deletes, because B-043 adds no delete endpoint.

## Import (`POST /import`)

Proposal: add an optional `focus_sessions` array with the same per-row rules as above. Existing ids are skipped (server copy wins, as for every other import table). Links may point at a to-do or schedule item in the same payload or one the user owns, and are written after those rows exist inside the same transaction. Capacity per call, and how it interacts with the chunked frontend import and the proposed B-044, is **Q-B043-5**. Until it is answered, the safe plan is to ship the direct endpoint first and add `focus_sessions` to import in a follow-up once B-044 settles capacity. That follow-up does not change the contract above.

## Open decisions (also in `docs/PROGRESS.md` Open questions)

- **Q-B043-0 Approach.** A (record finished sessions) is recommended over B (server lifecycle) and C (later edits).
- **Q-B043-1 Tracked time.** Proposal: tracked Focus time = active `focus` segment time only. Paused time is never tracked. Break time is stored separately (`break_ms`) and not counted as Focus.
- **Q-B043-2 Same-UUID retry with changed payload.** Proposal for `POST /focus-sessions`: an identical payload returns 200 with the stored row, a different payload returns 409, and the stored row stays unchanged. Import keeps its existing "skip, server wins" rule. The alternative is 200 with the stored row in both cases, which matches import but hides client bugs.
- **Q-B043-3 Minimum saved duration for an early finish.** Research D12 suggests the threshold applies to the whole session (at least 60 s of `focus_ms`), not to a phase. Under 60 s, should the server reject with 422 or accept it (the client simply does not send)? Proposal: the server rejects with 422 when `focus_ms < 60 000`, so the rule holds for every client.
- **Q-B043-4 Linked items that are deleted.** Proposal: a soft-deleted to-do keeps the link (the row still exists, and restore brings it back). A hard-deleted schedule item sets `schedule_item_id` to null, and the session and its time stay, with `label` keeping the title. Creating a session whose linked to-do is **already soft-deleted** is accepted, because an offline queue can send it after the user deleted the to-do. A link to a missing or other-user id is a 422. A schedule item that no longer exists is a 422, because its row is gone and there is nothing to link to. Alternative: drop the bad link and keep the session.
- **Q-B043-5 Guest history transfer and capacity.** How many sessions per import call and per account? Is there an account-level cap like the 500 to-dos? Proposal: no account cap (tracked time is history, not a plan), at most 1000 sessions per import call, and the import addition deferred until B-044 resolves chunking and atomicity.
- **Q-B043-6 Ownership status code.** Proposal: 404 for another user's session (I7 style), not 403 (Q7c style).

## What the frontend (F-R3-01) can rely on once approved

- Generate the session UUID when the timer starts and keep it in the persisted timer state, so a reload or retry reuses it.
- On finish (completed or ended early), `POST /focus-sessions` once. Treat 201 and 200 as saved, keep the item queued on network errors or 5xx, and surface 409 and 422 as non-retryable.
- Send times with an explicit offset and durations in integer ms. Compute `segments[].active_ms` excluding pauses, and send laps as cumulative ms.
- Guests store the same shape locally. Import support follows Q-B043-5.
- Until approval, nothing here is live. The frontend must not call these routes.
