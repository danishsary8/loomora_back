# 03 — Gap Analysis: DayCraft → Loomora (Phase 3)

Inputs: `01-feature-inventory.md` (feature #1–#59), `02-feature-behavior-and-evidence.md` (defects D1–D21), and your Loomora list (R1–R4, After R4, Out of scope).
Evidence labels carry over from Phase 2: **Observed / Static / Inferred / Unverified**. Anything about DayCraft's server side stays **Unverified** (backend NXDOMAIN).

How to read the lists:
- **List 1 — Already planned:** DayCraft has it and Loomora plans it. The "Spec delta" column says what Loomora must do differently or in addition.
- **List 2 — Missing:** DayCraft has it and Loomora's list does not. Each gets priority (Must / Should / Later) and a release.
- **List 3 — Improve:** DayCraft behavior that is wrong or weak (D1–D21), with the Loomora rule that replaces it.
- **List 4 (extra) — Loomora-only:** planned features with no DayCraft counterpart, so DayCraft gives no reference behavior. They need their own spec (Phase 4 covers them).
- **List 5 (extra) — Deliberately not carried over.**

---

## List 1 — Already planned

| Loomora item (release) | DayCraft counterpart | Evidence | Spec delta for Loomora |
|---|---|---|---|
| To-do add / delete / check off (R1) | Checklist add/tick/delete #40 | Static | Add **edit** (DayCraft has none), **due date**, **category color**, and a confirm or undo on delete (D18). Server-side ordering instead of client `max+1` (D17) |
| Today screen with to-do list (R1) | Dashboard #20–#26 + `/goals` #40–#41 | Static | Make it truly per-day (D7). No hidden "+N more…" items (D13). Keep the progress % + insight sentence idea (#41) |
| Guest mode (R1) | Anonymous Supabase user + `daycraft-guest-*` localStorage #4, #9, A2 | Observed (request) + Static | Loomora: Dexie (IndexedDB) store with **no server account needed**. Every write path must have a guest branch (D5) |
| Account: email + Google (R1) | #5, #6, #8, #10, #12 | Static (+ Observed guest sign-up request) | Laravel Sanctum. Keep the forgot/reset password flow (#10, #12; Missing M6 if it isn't listed). Add `autocomplete` attributes (D21). Email-confirmation policy at DayCraft: **Unverified** |
| Guest data migration (R1) | Upgrade links the account but **drops local data** #7 | Static | Real migration: upload the Dexie data in one idempotent import call, then clear local (D6) |
| Streak (R1) | #24 / D9: day counts if ≥ 1 `timer_runs` row | Static | Loomora rule: a day counts if ≥ 1 check-off. Compute in the **user's timezone** (D2). Decide grace: DayCraft shows 0 until you act today. Loomora should show yesterday's streak as "at risk" instead |
| Progress ring (R1) | Goals progress bar #41; focus/timer rings (D4, D7) | Static | Loomora ring = done/total for today. "Stamp animation" is Loomora-only |
| Settings: name, sign out (R1) | #50, #17 | Static | Keep the sign-out confirm dialog and the guest-specific "Keep my notebook → sign up" choice (#17) |
| Installable PWA (R1) | Only `manifest.json` (standalone, SVG icon); **no service worker** | Observed (manifest) + Static | Loomora adds a service worker, PNG icons 192/512, offline shell |
| Focus timer (R3) | Focus Mode #27–#34 | Static | Attach to a to-do or schedule item (DayCraft has no link: B9). Fix the double logging (D3), end-early loss (D12), and reload loss (D15) |
| Countdown 25/50/90 + custom (R3) | `/timer` presets 25/5/15/50 + custom 1 s–24 h #35–#37 | Static | Loomora presets 25/50/90. Keep DayCraft's wall-clock `endAt` engine and its persistence across reloads/pages (D4): this is the strongest part of DayCraft |
| Stopwatch with laps 1/100 s (R3) | #20, #38 (centiseconds, laps, split + cumulative) | Static | Persist across reload (D15). Save laps as ms, not floored seconds |
| Time tracking from sessions (R3) | `/time-tracking` #45–#46 | Static | One source of truth (sessions table) so nothing double-counts (D3). Keep filters, search, day grouping, delete-with-confirm |
| Timer history (R3) | Same screen, `/history` redirects there | Static | Same as above |
| Timer-end sound with mute (R3) | Alarm system #22, #51–#57 | Static | Mute + one default sound are planned. Selection, volume, modes, and custom upload are Missing items M9–M11 |
| Analytics (R4) | `/analytics` #47–#49 | Static | Fix the mislabeled total (D4), timezone bucketing (D2), and unbounded fetches (D16). Add completion trends and streak history (Loomora-only) |
| Notes, standalone (R4) | `/notes` #42–#44 | Static | Keep tags, pin, search, color. "Attached to a to-do" is Loomora-only |
| Accessibility polish (After R4) | Gaps D21 | Observed + Static | Some items should move to R1. See List 3 D21 |

---

## List 2 — Missing (DayCraft has it, Loomora's list doesn't)

| ID | Feature | DayCraft ref | Evidence | Priority | Release | Why / Loomora note |
|---|---|---|---|---|---|---|
| M1 | **Global running timer across pages + reload** (timer keeps going, completion fires anywhere, app-wide "running" indicator) | #18, D4 | Static | **Must** | R3 | Without it a focus session dies on navigation. Use a Zustand store persisted to localStorage, a root watcher, and wall-clock `endAt` |
| M2 | **Focus cycles: focus → short break → long break × N** with phase badges and per-phase alarm | #27, #30–#31, D7 | Static | **Must** | R3 | Pomodoro without breaks is a big gap vs DayCraft |
| M3 | **Custom focus presets** (name, focus, short, long, cycles; validation; sync to account) | #28 | Static | Should | R3 | Same validation messages are a good baseline |
| M4 | **Session intention / label** + "Est. completion" time | #29, #30 | Static | Should | R3 | When attached to a to-do, the intention defaults to the to-do title |
| M5 | **End session early, keep partial time** (completed flag) | #33 | Static | **Must** | R3 | Needed for honest Plan vs Reality. Fix the D12 rule |
| M6 | **Forgot / reset password flow** | #10, #12 | Static | **Must** | R1 | Implied by "account" but not listed. Email auth without reset is a support burden |
| M7 | **Full-screen distraction-free focus view** | #30 | Static | Should | R3 | |
| M8 | **Clock page (12/24 h, full screen)** | #39 | Static | Later | After R4 | Low value. A 12/24 h preference is still needed for the schedule (fold into R1 settings) |
| M9 | **Sound picker** (several sounds) + **preview** + **volume** | #53, #54, #56 | Static | Should | R3 | WebAudio synth = zero asset weight. Don't copy DayCraft's tones; design Loomora's own |
| M10 | **Playback mode** once / repeat / continuous + "Stop alarm" | #52, #31 | Static | Should | R3 | Fix the repeat count (D8) |
| M11 | **Custom sound upload** | #55 | Static | Later | After R4 | Store as a file in Laravel storage, not base64 (D10) |
| M12 | **Audio unlock on first gesture** (mobile autoplay) | #19 | Static | **Must** | R3 | Without it the timer-end sound silently fails on iOS/Android |
| M13 | **Profile avatar + bio** | #50 | Static | Later | After R4 | Name is planned; avatar optional. Use file storage (D10) |
| M14 | **Reset guest data** (wipe local notebook) | #59 | Static | Should | R1 | Pairs with guest mode and privacy. Must wipe **all** keys (D14) |
| M15 | **Sign-out / exit-guest confirmation with "keep my data → sign up"** | #17 | Static | **Must** | R1 | Prevents guests losing data |
| M16 | **Daily intention / "Today's goal" text** | #23 | Static | Should | R1 | Loomora version: per-day, synced, on the Today screen (fix D7) |
| M17 | **Today progress insight sentence** | #41 | Static | Later | R4 | Fits the weekly recap / Plan vs Reality tone |
| M18 | **Achievements / milestones** (toast on unlock) | #34, B6–B7 | Static (catalog **Unverified**) | Later | After R4 | If built: computed on the **server** (D9). Could merge with the "shareable stamp card" |
| M19 | **History filters (kind) + label search + delete with confirm** | #45–#46 | Static | Should | R3 | Listed as "timer history" but these details aren't |
| M20 | **Method share (time by mode) + completion-rate KPI** | #47, #49 | Static | Should | R4 | Fold into R4 analytics |
| M21 | **Notes: tags, pin, color, search** | #42–#44 | Static | Should | R4 | Notes are planned; these sub-features aren't listed |
| M22 | **Landing page with "Try as guest" CTA** | #1–#4 | Observed | **Must** | R1 | Entry point for guest mode and the PWA install |
| M23 | **Public blog / SEO pages + sitemap + JSON-LD** | #13, A1 | Static + Observed (sitemap) | Later | After R4 | Marketing, not product |
| M24 | **Global error states:** 404, crash boundary with retry, backend-unreachable message | §3.8, D1 | Observed | **Must** | R1 | DayCraft shows a raw "Failed to fetch". Loomora needs a friendly offline/server-down state (D1) |
| M25 | **Empty and loading states per screen** | §3.8 | Static | **Must** | R1 | Write Loomora copy for each |
| M26 | **Optimistic check-off** | #40 | Static | **Must** | R1 | Check-off must feel instant (it drives the stamp animation) |
| M27 | **Recent laps on the Today/home screen** | #26 | Static | Later | R3 | Minor |

Not marked Missing on purpose: themes (#2, #58), Out of scope per your list. Garden (B8), dead code in DayCraft (D20).

---

## List 3 — DayCraft behavior to improve (D1–D21 → Loomora rule)

| Defect | DayCraft behavior | Evidence | Loomora rule | Release |
|---|---|---|---|---|
| D1 | Backend gone; app shows "Failed to fetch" and nothing works, guest mode included | **Observed** | Guest mode must be **local-first** (Dexie) and work with no backend at all. Show a friendly offline/server banner; queue writes for accounts | R1 |
| D2 | Streak and 7-day chart compare UTC date keys with local midnight | Static (runtime **Unverified**) | Store `user.timezone` (IANA). The server computes `local_date` per event. Streak and charts group by local date. Add tests for UTC+5:30, UTC+7 (Cambodia), UTC−8 around midnight | R1 (streak), R4 (charts) |
| D3 | Each focus session is saved twice (`focus_sessions` + `timer_runs`) and history sums both | Static (runtime **Unverified**) | One `sessions` table (`kind` = focus/countdown/stopwatch). History and analytics read only that | R3 |
| D4 | "Total focus time" sums every kind | Static | Separate "Focus time" (focus kind only) from "Tracked time" (all kinds), with clear labels | R4 |
| D5 | `/stopwatch` Save has no guest branch | Static | One repository layer (`ApiRepo` / `DexieRepo`) chosen by auth state. No per-component `if (isGuest)` | R1 (pattern), R3 (stopwatch) |
| D6 | Guest → account upgrade drops local data | Static | `POST /api/import` with all Dexie records and client UUIDs, idempotent, in one transaction. Clear local only after a 2xx | R1 |
| D7 | "Today's Goals" never reset; goal text is device-only | Static | To-dos have `due_date` (null = inbox). Today = due today + overdue carry-over + today's schedule. Daily intention stored per date on the server | R1 |
| D8 | "Repeat (3x)" plays 4 times | Static | Repeat N plays exactly N; unit-test the count | R3 |
| D9 | Achievements evaluated and inserted by the client; RLS **Unverified** | Static | All derived stats (streak, achievements, stamps) computed and stored **server-side**. Client inserts never grant rewards | R1 (streak), later (achievements) |
| D10 | Avatar and custom audio stored as base64 data-URLs in DB/localStorage | Static | Laravel file storage (S3/local disk) + URL. Size limits enforced server-side | After R4 |
| D11 | React #418 hydration mismatch on `/auth` | **Observed** | Loomora is a Vite SPA (no SSR), so this doesn't apply. Still: no render-time `Date.now()`/locale text differences between first paint and hydration | n/a |
| D12 | Ending a session < 60 s into a phase discards earlier completed phases | Static | Always save the accrued time if total ≥ 60 s (the threshold applies to the session total, not the phase) | R3 |
| D13 | Items past 6/20 are hidden behind "+N more…" with no link | Static | No silent caps. Show everything, or link to the full list | R1 |
| D14 | "Reset guest data" leaves presets, sound, timer, and theme behind | Static | Reset = delete the Dexie DB + all `loomora-*` localStorage keys | R1 |
| D15 | Running stopwatch and focus session lost on reload or navigation | Static | Persist the timer state (`startedAt`, `pausedAt`, `accumulatedMs`, phase) like the countdown; restore on load | R3 |
| D16 | Dashboard and analytics fetch all rows, unbounded | Static | Paginated history (cursor), server-side aggregate endpoints (`/api/stats?from&to`) | R3/R4 |
| D17 | `sort_order = max+1` computed on the client; no reorder | Static | Server assigns position (or fractional index); add drag-reorder later | R1 (server order) |
| D18 | Notes pin/delete errors swallowed; no undo for deletes | Static | Every mutation shows errors. Deletes get a 5 s "Undo" toast (soft delete) | R1 (to-dos), R4 (notes) |
| D19 | `started_at` back-computed; breaks not recorded | Static | Record real `started_at`/`ended_at` and each phase segment. Needed for Plan vs Reality | R3 |
| D20 | Garden/achievements have data but no UI; dead `daycraft-guest-id` key | Static | Ship nothing half-built. Each stored entity needs a screen or doesn't exist | all |
| D21 | Password inputs lack `autocomplete`; clickable `div`s; color-only active states | Observed + Static | `autocomplete="email"/"current-password"/"new-password"`, real buttons, `aria-current="page"`, visible focus rings. Basics in R1; full audit after R4 | R1 + After R4 |

Unverified items that may become defects once a backend is reachable (from Phase 2 §G): whether reads are really scoped by RLS (most DayCraft selects have **no** `user_id` filter), the `profiles` auto-creation trigger, the `notes.updated_at` trigger, email confirmation, rate limits. Loomora avoids all of these by scoping every query on the server by `auth()->id()` (Laravel policies).

---

## List 4 — Loomora-only (no DayCraft reference; spec from scratch)

| Feature | Release | Notes for Phase 4 |
|---|---|---|
| Fixed weekly schedule (time + repeat days) | R1 | New `schedule_items` (title, start/end local time, weekday mask, color). DayCraft only has a static marketing mock-up (Phase 1 F2) |
| Now/Next strip | R1 | Derived from today's schedule items + the user's timezone (avoid D2) |
| Week view | R1 | 7 columns from Mon/Sun (setting). Mobile 375 px = day-swipe or a compact grid |
| "Only today" vs "All repeats" edit/delete | R1 | Needs `schedule_exceptions` (date, skip/override) |
| Overdue carry-over | R1 | Undone to-dos with `due_date < today(local)` show on Today in an "Overdue" group |
| Category color on to-dos | R1 | Fixed palette that works on cream #F5EDE6 |
| Stamp animation | R1 | Fires on check-off (optimistic, M26). Respect `prefers-reduced-motion` |
| Delete account | R1 | Server cascade delete + Sanctum token revoke; Google account unlink |
| Feedback button | R1 | Simple `POST /api/feedback` |
| Copy a day | R1 | Copy one date's to-dos (and schedule overrides) to another date |
| Subtasks, repeating to-dos, in-app reminders, priority, to-do notes | R2 | Reminders need M1-style global watcher + M12 audio unlock |
| Focus attached to a to-do/schedule item | R3 | `sessions.task_id` / `schedule_item_id` (DayCraft has no link, B9) |
| Completion trends, streak history, weekly recap, Plan vs Reality | R4 | Needs D19 real timestamps and planned durations from schedule items |
| Khmer language | After R4 | i18next from R1 (no hard-coded strings) so it's a translation job later. DayCraft has no i18n (Static) |
| Push notifications, quick-add by typing, shareable stamp card, data export | After R4 | |

## List 5 — Deliberately not carried over

| DayCraft item | Reason |
|---|---|
| 9 themes + live theme preview (#2, #58) | Out of scope (single neo-brutalism cream theme) |
| Notebook visual style (paper texture, tape strips, tilted cards, Inter "hand" font) | Copy behavior, not design |
| Garden (B8), dead keys (D20) | Not implemented in DayCraft either |
| Supabase direct-from-browser data access | Loomora uses a Laravel API with server-side authorization |

---

## Summary counts
- Already planned: **18** (all need at least one spec delta).
- Missing: **27**. **Must 10** (M1, M2, M5, M6, M12, M15, M22, M24, M25, M26) · **Should 10** · **Later 7**.
- Improve: **21** defects mapped. **R1-blocking:** D1, D2, D5 (pattern), D6, D7, D9 (streak), D13, D14, D17, D18, D21 (basics).
- By release, Missing Musts land in **R1:** M6, M15, M22, M24, M25, M26 · **R3:** M1, M2, M5, M12.
