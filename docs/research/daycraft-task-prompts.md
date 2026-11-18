# 04 — Build Plan: Task Prompts for Claude Code (Phase 4)

Source: `03-gap-analysis.md` → **Missing (M1–M27)** and **Improve (D1–D21)**. Loomora-only features (schedule, Week view, Now/Next, copy day, stamp…) are **not** in this file: they belong to the planner's P5–P7 tasks. Each task below lists the planner task it depends on when it needs one.

Each task ≈ 1–2 h, one concern, and fits the `docs/TASKS.md` format from `loomora-docs-06-roadmap-and-automation.md` (id, title, status, steps, done-when, files). Copy a task block into `docs/TASKS.md` with `status: todo`.

---

## 0. Conventions every prompt assumes (paste once into `CLAUDE.md` or `docs/DECISIONS.md`)

These match the v2 rebuild (decision S1: old code moves to `legacy/` on branch `v2-rebuild`). **If A8 (repo structure) chose different paths, use A8's equivalents and note the mapping in `docs/DECISIONS.md`. Do not stop to ask.**

**Backend repo `loomora_back`:** Laravel 12, PHP 8.3, PostgreSQL, Sanctum tokens.
- Routes `routes/api.php` under prefix `/api/v1`, `auth:sanctum` unless stated.
- Controllers `app/Http/Controllers/Api/V1/`, FormRequests `app/Http/Requests/`, Resources `app/Http/Resources/`, Policies `app/Policies/`, services `app/Services/`.
- Every query scoped to `$request->user()` (never trust a client `user_id`).
- Tests: PHPUnit feature tests in `tests/Feature/`, run with `php artisan test`. Style check `./vendor/bin/pint --test`.
- Every new or changed endpoint is documented in `docs/api.md` (decision S2: the contract).

**Frontend repo `Loomora_front`:** React 18, Vite, TypeScript strict, Tailwind 3, TanStack Query, Zustand, Dexie, i18next.
- Features in `src/features/<feature>/`, Dexie in `src/lib/db/`, repositories in `src/lib/repo/`, HTTP client + query hooks in `src/lib/api/`, Zustand stores in `src/stores/`, shared UI in `src/components/`.
- **All user-visible text via `t()`** with keys in `src/i18n/locales/en.json` (Khmer later is a translation job only).
- Tests: Vitest + Testing Library colocated `*.test.ts(x)`; Playwright e2e in `e2e/*.spec.ts` (decision S10).
- Commands: `npm run lint`, `npm run typecheck`, `npm test`, `npx playwright test`.

**IDs:** every entity gets a **client-generated UUID** (`crypto.randomUUID()`) that the server accepts as the primary key. This makes guest→account import idempotent (see D6).
**Time:** the server stores UTC `timestamptz`. Each user has an IANA `timezone`. "Day" always means the **user's local date** (see D2).
**UI:** neo-brutalism on cream `#F5EDE6`, tokens and components from B2/B4, phone-first at 375 px. **Do not copy DayCraft code or visuals.** DayCraft references (`evidence/bundle/...`) are for behavior only.
**Workflow (from the roadmap):** branch `feat/<task-id>-<slug>`, conventional commits, run lint + typecheck + tests before the PR, mark the task `review`, append to `docs/PROGRESS.md`. Anything needing secrets or data deletion → `blocked` with a question.

---

## 1. Task index

| ID | Title | Side | Phase | Priority | Covers |
|---|---|---|---|---|---|
| DC-01 | Repository layer: one data API, guest vs account backends | FE | P4 | Must | D5, D1 |
| DC-02 | Dexie schema + guest works with the backend offline | FE | P4 | Must | D1 |
| DC-03 | `POST /api/v1/import`: idempotent guest import | BE | P4 | Must | D6 |
| DC-04 | Guest → account migration flow on the client | FE | P4 | Must | D6 |
| DC-05 | Password reset API (forgot + reset) | BE | P4 | Must | M6 |
| DC-06 | Forgot / reset password screens | FE | P4 | Must | M6 |
| DC-07 | Sign-out / exit-guest confirm sheet with "Keep my plan" | FE | P4 | Must | M15 |
| DC-08 | Reset guest data wipes everything | FE | P4 | Should | M14, D14 |
| DC-09 | Landing "Try it now" starts guest with no network | FE | P4 | Must | M22, D1 |
| DC-10 | User timezone + time-format prefs and local-date helper | BE | P5 | Must | D2, M8 |
| DC-11 | Client local-date + time-format utilities | FE | P5 | Must | D2, M8 |
| DC-12 | Server-assigned to-do position | BE | P5 | Should | D17 |
| DC-13 | Optimistic check-off with rollback | FE | P5 | Must | M26, D18 |
| DC-14 | To-do soft delete + restore endpoint | BE | P5 | Should | D18 |
| DC-15 | Undo toast for deletes | FE | P5 | Should | D18 |
| DC-16 | Today list: no silent caps | FE | P7 | Must | D13 |
| DC-17 | Streak service (server, local-date, at-risk) | BE | P7 | Must | D2, D9 |
| DC-18 | Streak on the client (guest calc + header display) | FE | P7 | Must | D2, D9 |
| DC-19 | Daily intention per date: API | BE | P7 | Should | M16, D7 |
| DC-20 | Daily intention on Today | FE | P7 | Should | M16, D7 |
| DC-21 | Error boundary, 404, backend-unreachable banner | FE | P8 | Must | M24, D1 |
| DC-22 | Loading skeletons + empty states for R1 screens | FE | P8 | Must | M25 |
| DC-23 | Auth and nav accessibility basics | FE | P8 | Must | D21 |
| DC-30 | `sessions` table: single source of tracked time | BE | P10 | Must | D3, D19 |
| DC-31 | Session history API: cursor pagination, filter, search | BE | P10 | Should | M19, D16 |
| DC-32 | Timer engine store (countdown / stopwatch / focus), persisted | FE | P10 | Must | M1, D15 |
| DC-33 | Root timer watcher + running indicator | FE | P10 | Must | M1 |
| DC-34 | Audio service: unlock, mute, synth sounds, exact repeat | FE | P10 | Must | M12, D8 |
| DC-35 | Focus cycle planner (pure logic) | FE | P10 | Must | M2, M4 |
| DC-36 | Focus session screen: phases, attach to to-do, end early | FE | P10 | Must | M2, M4, M5, D12 |
| DC-37 | Full-screen focus view | FE | P10 | Should | M7 |
| DC-38 | Focus presets API | BE | P10 | Should | M3 |
| DC-39 | Focus presets UI | FE | P10 | Should | M3 |
| DC-40 | Sound settings: picker, preview, volume, play mode | FE | P10 | Should | M9, M10 |
| DC-41 | History screen: filters, search, delete + undo | FE | P10 | Should | M19, D18 |
| DC-50 | Stats summary API (local-date buckets, focus vs tracked) | BE | P11 | Must | D2, D4, D16, M20 |
| DC-51 | Analytics KPIs + method share | FE | P11 | Should | D4, M20 |
| DC-52 | Notes API: tags, pin, color, search, soft delete | BE | P11 | Should | M21, D18 |
| DC-53 | Notes UI: tags, pin, color, search, undo | FE | P11 | Should | M21, D18 |
| DC-54 | Today insight sentence | FE | P11 | Later | M17 |
| DC-60 | Achievements computed on the server | BE | P12+ | Later | M18, D9 |
| DC-61 | File storage for avatar and custom sound | BE | P12+ | Later | M11, M13, D10 |
| DC-62 | Custom alarm sound upload UI | FE | P12+ | Later | M11 |
| DC-63 | Recent laps card | FE | P12+ | Later | M27 |
| DC-64 | Public SEO pages: sitemap, meta, JSON-LD | FE | P12+ | Later | M23 |

Not given tasks: M8 full clock page (Later, low value; the 12/24 h part is in DC-10/11), D11 (n/a: Loomora has no SSR), D20 (rule, enforced in review).

Suggested order: DC-01 → 02 → 09 → 03 → 04 → 05 → 06 → 07 → 08 → 10 → 11 → (planner P5 to-do tasks) → 12 → 13 → 14 → 15 → 16 → 17 → 18 → 19 → 20 → 21 → 22 → 23 → R3 block → R4 block → Later.

---

## 2. R1 tasks

### DC-01 · Repository layer: one data API, guest vs account backends
- **Side:** frontend · **Phase:** P4 · **Priority:** Must · **Covers:** D5, D1
- **Depends on:** C0 scaffold (Vite+TS, TanStack Query, Dexie installed)

**Prompt:**
Create a repository layer so no component ever branches on "is guest". DayCraft branched in every component and forgot one (its stopwatch saved guest data to the server, `02-feature-behavior-and-evidence.md` D6/D5). Loomora must make that mistake impossible.
1. In `src/lib/repo/types.ts` define a generic `Repo<T extends {id: string}>` with `list(query?)`, `get(id)`, `create(input)`, `update(id, patch)`, `remove(id)`, `restore(id)`. Define `RepoRegistry` with one entry per entity (start with `todos`; leave typed slots for `scheduleItems`, `sessions`, `notes`).
2. `src/lib/repo/dexieRepo.ts`: implementation over a Dexie table. Generates `id` with `crypto.randomUUID()` when missing and sets `created_at`/`updated_at` ISO strings. `remove` sets `deleted_at` (soft delete); `list` hides soft-deleted rows.
3. `src/lib/repo/apiRepo.ts`: implementation over the axios client in `src/lib/api/client.ts` at `/api/v1/<resource>`. Sends the client `id` on create.
4. `src/lib/repo/index.ts`: `useRepos()` hook returns the Dexie registry when the auth store mode is `guest`, the API registry when `account`. Switching mode resets the TanStack Query cache.
5. Add an ESLint `no-restricted-syntax` (or `no-restricted-imports`) rule that forbids importing `dexie` or `src/lib/api/client` from `src/features/**`. Features use `useRepos()` only.

**Done when:**
- `npm test` passes `src/lib/repo/*.test.ts`: in guest mode create→list returns the item from Dexie (use `fake-indexeddb`), and the API repo is never called; in account mode the API repo is called (mock axios).
- `npm run lint` fails on a deliberate test fixture that imports `dexie` inside `src/features/` (then remove the fixture).
- `npm run typecheck` passes.

**Files likely touched:** `src/lib/repo/{types,dexieRepo,apiRepo,index}.ts`, `src/lib/repo/*.test.ts`, `src/stores/auth.ts`, `.eslintrc.*`, `package.json` (`fake-indexeddb` dev dep).

---

### DC-02 · Dexie schema + guest works with the backend offline
- **Side:** frontend · **Phase:** P4 · **Priority:** Must · **Covers:** D1
- **Depends on:** DC-01

**Prompt:**
DayCraft's guest mode needed a Supabase anonymous login, so when its backend vanished nobody could even try the app (`01-feature-inventory.md` F1, Observed). Loomora's guest mode must never touch the network.
1. `src/lib/db/schema.ts`: Dexie database `loomora` version 1 with tables `todos` (`id, due_date, done_at, deleted_at, position`), `scheduleItems` (`id, deleted_at`), `scheduleExceptions` (`id, schedule_item_id, date`), `sessions` (`id, started_at, kind, todo_id, deleted_at`), `notes` (`id, updated_at, pinned, deleted_at`), `dailyIntentions` (`date`), `meta` (`key`). Export typed table interfaces from `src/lib/db/types.ts` that mirror `docs/api.md` resources.
2. `src/stores/auth.ts`: `startGuest()` sets `mode: 'guest'` and a `guestSince` timestamp in `meta`. It makes **no** HTTP call. Persist the mode in localStorage key `loomora-auth-mode`.
3. Ensure nothing in guest mode calls the API: the axios client throws `GuestModeNetworkError` if called while `mode === 'guest'` (defense in depth).

**Done when:**
- Playwright `e2e/guest-offline.spec.ts`: with `page.route('**/api/**', r => r.abort())`, open `/`, tap "Try it now", add a to-do, reload, and the to-do is still there; zero requests reached `/api/` (assert with a request counter).
- Vitest: the axios client throws `GuestModeNetworkError` in guest mode.
- `npm run typecheck && npm test && npx playwright test e2e/guest-offline.spec.ts` all pass.

**Files likely touched:** `src/lib/db/{schema,types}.ts`, `src/stores/auth.ts`, `src/lib/api/client.ts`, `e2e/guest-offline.spec.ts`.

---

### DC-03 · `POST /api/v1/import` — idempotent guest import
- **Side:** backend · **Phase:** P4 · **Priority:** Must · **Covers:** D6
- **Depends on:** to-do, schedule, and exception migrations from the planner's P5/P6 tasks (if a table doesn't exist yet, import only the existing ones and leave a `TODO(DC-03)` comment plus a PROGRESS note)

**Prompt:**
DayCraft linked a guest to an account but never uploaded the guest's local data (`02…` D2 "Guest upgrade", defect D6). Build the server half of a real migration.
1. Route `POST /api/v1/import` (auth:sanctum). Body: `{ todos: [], schedule_items: [], schedule_exceptions: [], sessions: [], notes: [], daily_intentions: [] }`. Each record carries its client UUID `id` and timestamps.
2. `ImportRequest` validation: UUIDs, max 5,000 records total, per-field rules matching each resource's normal store request (reuse the rules).
3. `App\Services\GuestImportService`: one DB transaction. **Upsert by `id`** scoped to the current user: an id that already belongs to the user is updated only if the incoming `updated_at` is newer; an id that belongs to **another** user → reject the whole import with 409. Force `user_id = auth user`.
4. Response `200 { imported: {todos: n, …}, skipped: {…} }`.
5. Document it in `docs/api.md`.

**Done when** `php artisan test --filter=ImportTest` passes these cases:
- importing the same payload twice gives no duplicate rows, and the second response reports every row `skipped`
- an id owned by another user → 409 with nothing written (transaction rolled back)
- invalid UUID → 422
- payload over 5,000 records → 422
- `user_id` in the payload is ignored

`./vendor/bin/pint --test` passes.

**Files likely touched:** `routes/api.php`, `app/Http/Controllers/Api/V1/ImportController.php`, `app/Http/Requests/ImportRequest.php`, `app/Services/GuestImportService.php`, `tests/Feature/ImportTest.php`, `docs/api.md`.

---

### DC-04 · Guest → account migration flow on the client
- **Side:** frontend · **Phase:** P4 · **Priority:** Must · **Covers:** D6
- **Depends on:** DC-01, DC-02, DC-03, account sign-up/login screens (planner P4)

**Prompt:**
After a guest signs up or logs in, move their Dexie data to the account safely.
1. `src/features/auth/migrateGuestData.ts`: read every Dexie table (including soft-deleted rows) and POST to `/api/v1/import` in one call. On **2xx**: clear the Dexie tables and set `meta.migratedAt`. On failure: keep the local data, set `meta.pendingImport = true`, and show a toast via `t('migration.failed')` with a Retry action.
2. Call it once right after a successful sign-up or login **when the previous mode was guest and Dexie has data**. Switch the repo mode to `account` only after migration succeeds or the user taps "Skip and keep local copy" (keep the local data then).
3. On app start in account mode with `meta.pendingImport`, retry automatically once.
4. Success toast `t('migration.done', {count})`.

**Done when:**
- Vitest (`migrateGuestData.test.ts`, mocked axios): success clears Dexie; a 500 keeps the data and sets `pendingImport`; a 409 keeps the data and shows the error copy.
- Playwright `e2e/guest-to-account.spec.ts` (mock `/api/v1/register` and `/api/v1/import` with `page.route`): a guest adds 2 to-dos, signs up, the import body contains 2 to-dos with the same ids, and the Today list shows them after the switch.
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/features/auth/migrateGuestData.ts(+test)`, `src/features/auth/useAuthActions.ts`, `src/stores/auth.ts`, `src/i18n/locales/en.json`, `e2e/guest-to-account.spec.ts`.

---

### DC-05 · Password reset API (forgot + reset)
- **Side:** backend · **Phase:** P4 · **Priority:** Must · **Covers:** M6
- **Depends on:** email sign-up API; Brevo mailer configured (decision S7). If the Brevo credentials are missing, use the `log` mailer in tests and mark the live-send check `blocked`.

**Prompt:**
DayCraft has a forgot-password → email link → set-new-password flow (`01…` #10, #12). Loomora's R1 list doesn't mention it, but email accounts need it.
1. `POST /api/v1/password/forgot {email}`: always returns `202 {message}` whether or not the email exists (no account enumeration). Throttle 5/min per IP+email.
2. Send the reset email with Laravel's Password broker. The link goes to `${FRONTEND_URL}/reset-password?token=…&email=…`.
3. `POST /api/v1/password/reset {email, token, password, password_confirmation}`: min 8 chars, `confirmed`. On success: revoke all Sanctum tokens and return 200. Invalid or expired token → 422 `{errors:{token:[…]}}`.
4. Document in `docs/api.md`.

**Done when** `php artisan test --filter=PasswordResetTest` covers:
- unknown email still returns 202 and sends no mail (`Mail::fake`/`Notification::fake`)
- a known email gets exactly one notification
- a valid token resets the password, the old token stops working, and the old Sanctum tokens are deleted
- an expired or wrong token → 422
- a 6th request in a minute → 429

Pint passes.

**Files likely touched:** `routes/api.php`, `app/Http/Controllers/Api/V1/PasswordResetController.php`, `app/Http/Requests/{ForgotPasswordRequest,ResetPasswordRequest}.php`, `app/Notifications/ResetPasswordNotification.php`, `tests/Feature/PasswordResetTest.php`, `docs/api.md`.

---

### DC-06 · Forgot / reset password screens
- **Side:** frontend · **Phase:** P4 · **Priority:** Must · **Covers:** M6
- **Depends on:** DC-05, sign-in screen (planner P4)

**Prompt:**
1. On sign-in (S10) add a "Forgot password?" link → `/forgot-password`: email field (`type=email`, `autocomplete="email"`), submit → `POST /password/forgot` → always show `t('reset.sent')` ("If an account exists, a link is on its way"). Then link back to sign-in.
2. `/reset-password?token&email`: new password + confirm (`autocomplete="new-password"`, min 8, show/hide toggle). Client check that they match. Submit → `POST /password/reset` → toast `t('reset.done')` → `/signin`. A 422 on the token shows the expired state `t('reset.expired')` with a button to request a new link. Missing token → the same expired state.
3. Buttons show a busy state and are disabled while submitting. Server field errors render under their fields.

**Done when:**
- Vitest: mismatch blocks submit; 422 token → expired state; missing query → expired state.
- Playwright `e2e/password-reset.spec.ts` with mocked routes covers forgot → sent message, and reset → redirect to `/signin`.
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/features/auth/{ForgotPasswordPage,ResetPasswordPage}.tsx(+tests)`, `src/app/routes.tsx`, `src/i18n/locales/en.json`, `e2e/password-reset.spec.ts`.

---

### DC-07 · Sign-out / exit-guest confirm sheet with "Keep my plan"
- **Side:** frontend · **Phase:** P4 · **Priority:** Must · **Covers:** M15
- **Depends on:** Profile screen S9 (planner)

**Prompt:**
DayCraft confirms sign-out, and for guests offers "Keep my notebook" → sign-up before leaving (`01…` #17). Loomora needs the same protection.
1. `src/features/profile/SignOutSheet.tsx` (bottom sheet from the B4 components).
   - **Account:** title `t('signout.title')`, buttons Cancel / Sign out. Sign out: call `POST /api/v1/logout`, clear the token, clear the TanStack cache, go to `/`.
   - **Guest:** title `t('exitGuest.title')`, body explains the data stays **on this device** (Loomora keeps guest data local and does not delete it on exit), buttons Cancel / "Save my plan" (→ sign-up) / "Exit guest mode".
2. The sheet can't be dismissed while the request is running. Buttons show a busy state.
3. A failed logout request still clears the local token (log the error), so the user is never stuck signed in.

**Done when:**
- Vitest covers the account flow (calls logout, navigates to `/`), the guest flow (shows 3 buttons; "Save my plan" navigates to `/signup`), and a logout 500 (token still cleared).
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/features/profile/SignOutSheet.tsx(+test)`, `src/features/profile/ProfilePage.tsx`, `src/stores/auth.ts`, `src/i18n/locales/en.json`.

---

### DC-08 · Reset guest data wipes everything
- **Side:** frontend · **Phase:** P4 · **Priority:** Should · **Covers:** M14, D14
- **Depends on:** DC-02, DC-07

**Prompt:**
DayCraft's "Reset guest data" missed 4 of its own keys (`02…` D14). Loomora's reset must leave nothing behind.
1. `src/lib/db/resetLocal.ts`: `db.delete()` the whole Dexie DB, then remove **every** localStorage key that starts with `loomora-`. Keep a single exported list `LOCAL_KEY_PREFIX = 'loomora-'` and require all local keys to use it (add a unit test that greps `src/` for `localStorage.setItem(` calls whose key is a string literal not starting with `loomora-`).
2. Profile (guest only): "Reset this device" → confirm sheet (`t('resetGuest.confirm')`, danger button) → reset → reload to `/`.

**Done when:**
- Vitest: after seeding Dexie and 3 `loomora-*` keys plus 1 unrelated key, `resetLocal()` leaves Dexie empty, the `loomora-*` keys gone, and the unrelated key intact.
- The key-prefix grep test passes.
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/lib/db/resetLocal.ts(+test)`, `src/features/profile/ResetDeviceSheet.tsx`, `src/lib/storageKeys.ts`, `src/i18n/locales/en.json`.

---

### DC-09 · Landing "Try it now" starts guest with no network
- **Side:** frontend · **Phase:** P4 · **Priority:** Must · **Covers:** M22, D1
- **Depends on:** DC-02, landing screen S1 (planner B3 wireframe)

**Prompt:**
DayCraft's "Try as Guest" failed with a raw "Failed to fetch" toast when its backend went away (Observed, `evidence/screenshots/m375-guest_result.png`).
1. Wire the landing CTA "Try it now" to `startGuest()` (DC-02), then navigate to `/today`. No network call.
2. If the user is already in guest or account mode, the CTA reads `t('landing.continue')` and goes straight to `/today`.
3. "Sign in" goes to `/signin`.

**Done when:** Playwright `e2e/landing.spec.ts`:
- with all `/api/**` aborted, tapping "Try it now" lands on `/today` showing the S2 empty state
- a second visit to `/` shows "Continue"
- at the 375×812 viewport there is no horizontal scroll (`document.documentElement.scrollWidth <= 375`)

**Files likely touched:** `src/features/landing/LandingPage.tsx`, `src/stores/auth.ts`, `e2e/landing.spec.ts`, `src/i18n/locales/en.json`.

---

### DC-10 · User timezone + time-format prefs and local-date helper (backend)
- **Side:** backend · **Phase:** P5 · **Priority:** Must · **Covers:** D2, M8 (12/24 h)
- **Depends on:** users table (C0/C1)

**Prompt:**
DayCraft compared UTC date keys with local midnight, so streaks broke in UTC+ zones (`02…` D9, Static). Every "day" in Loomora is the user's local date, computed on the server.
1. Migration: `users.timezone` string not null default `'UTC'`, `users.time_format` enum `'12h'|'24h'` default `'24h'`, `users.week_starts_on` smallint (0–6) default 1.
2. `PATCH /api/v1/me/preferences {timezone?, time_format?, week_starts_on?}`: validate `timezone` with `Rule::in(DateTimeZone::listIdentifiers())`.
3. `App\Support\LocalDate`: `forUser(User $u, CarbonInterface $instantUtc): string` (Y-m-d in the user's tz), `todayFor(User $u): string`, `rangeUtc(User $u, string $localDate): array{CarbonImmutable, CarbonImmutable}` (start/end of that local day in UTC).
4. Return the prefs in `GET /api/v1/me`. Document in `docs/api.md`.

**Done when** `php artisan test --filter=LocalDateTest` covers:
- `Asia/Phnom_Penh` 2026-11-19 00:30 local → `2026-11-19` (UTC is still the 18th)
- `Asia/Kolkata` 23:59 vs 00:01 straddling UTC 18:30
- `America/Los_Angeles` 17:00 local → same local date while UTC is the next day
- a DST transition day (`America/New_York` 2026-03-08) where `rangeUtc` spans 23 h
- an invalid timezone in PATCH → 422

Pint passes.

**Files likely touched:** `database/migrations/*_add_preferences_to_users.php`, `app/Support/LocalDate.php`, `app/Http/Controllers/Api/V1/PreferencesController.php`, `app/Http/Requests/UpdatePreferencesRequest.php`, `app/Http/Resources/UserResource.php`, `tests/Feature/LocalDateTest.php`, `docs/api.md`.

---

### DC-11 · Client local-date + time-format utilities
- **Side:** frontend · **Phase:** P5 · **Priority:** Must · **Covers:** D2, M8
- **Depends on:** DC-10

**Prompt:**
1. `src/lib/time/localDate.ts`: `localDate(instant: Date | string, tz: string): string` using `Intl.DateTimeFormat('en-CA', {timeZone: tz})` (never `toISOString().slice(0,10)`). Also `todayLocal(tz)` and `addDays(localDate, n)` (pure string date math, no Date-at-midnight tricks).
2. `src/lib/time/format.ts`: `formatClock(date, tz, '12h'|'24h')`, `formatDuration(seconds)` → `M:SS` / `H:MM:SS`.
3. Guest tz = `Intl.DateTimeFormat().resolvedOptions().timeZone`, saved in Dexie `meta`. Account tz comes from `/me`. On first sign-in, if the server tz is `UTC` and the device differs, PATCH the device tz.
4. Add an ESLint `no-restricted-syntax` rule banning `toISOString().slice(0, 10)` (and `.substring(0,10)` on ISO strings) in `src/`.
5. Settings: a 12 h/24 h toggle and a timezone picker (searchable list of `Intl.supportedValuesOf('timeZone')`).

**Done when:**
- Vitest cases mirror DC-10 exactly. Put the shared cases in `src/lib/time/__fixtures__/local-date-cases.json` and copy the same JSON to the backend `tests/Fixtures/local-date-cases.json`; both suites read it.
- The lint rule flags a fixture file that uses `toISOString().slice(0,10)`.
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/lib/time/{localDate,format}.ts(+tests)`, `src/lib/time/__fixtures__/local-date-cases.json`, `src/features/profile/PreferencesSection.tsx`, `.eslintrc.*`, `src/i18n/locales/en.json`.

---

### DC-12 · Server-assigned to-do position
- **Side:** backend · **Phase:** P5 · **Priority:** Should · **Covers:** D17
- **Depends on:** to-do model/API (planner P5)

**Prompt:**
DayCraft computed `sort_order = max + 1` in the browser, which races across devices (`02…` D8).
1. `todos.position` (numeric/decimal, not null). On create the server sets `position = max(position for user, same due_date) + 1024` inside a transaction (`lockForUpdate` on the user row). The client never sends a position on create.
2. `PATCH /api/v1/todos/{id}/move {before_id?: uuid, after_id?: uuid}`: new position = midpoint of the neighbors. When the gap is < 0.0001, renumber that day's list in steps of 1024.
3. List order: `done_at IS NULL DESC, position ASC, created_at ASC`.

**Done when** `php artisan test --filter=TodoPositionTest` covers:
- 2 creates sent concurrently (simulate sequentially with the same max) produce distinct positions
- a move between 2 items lands between them
- 60 repeated moves into the same gap trigger a renumber and keep order
- moving another user's to-do → 403

`docs/api.md` updated. Pint passes.

**Files likely touched:** migration, `app/Models/Todo.php`, `app/Services/TodoPositionService.php`, `app/Http/Controllers/Api/V1/TodoController.php`, `tests/Feature/TodoPositionTest.php`, `docs/api.md`.

---

### DC-13 · Optimistic check-off with rollback
- **Side:** frontend · **Phase:** P5 · **Priority:** Must · **Covers:** M26, D18
- **Depends on:** to-do list UI (planner P5), DC-01

**Prompt:**
Check-off drives the stamp animation, so it must feel instant. DayCraft did an optimistic tick but swallowed some errors (`02…` D8, D12).
1. `src/features/todos/useToggleTodo.ts`: a TanStack `useMutation` with `onMutate` (cancel queries, snapshot, set `done_at = now`/`null` in the cache), `onError` (restore the snapshot + toast `t('todos.toggleFailed')` with Retry), `onSettled` (invalidate `['todos', localDate]` and `['streak']`).
2. Guest mode goes through the same hook via `useRepos()`; Dexie writes are awaited, and errors are handled the same way.
3. Double taps within 300 ms are ignored (per-id in-flight guard).

**Done when:**
- Vitest + Testing Library with a mocked repo: a tick updates the UI before the repo resolves; a rejected repo call rolls back and shows the toast; a double tap issues one call.
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/features/todos/useToggleTodo.ts(+test)`, `src/features/todos/TodoRow.tsx`, `src/i18n/locales/en.json`.

---

### DC-14 · To-do soft delete + restore endpoint
- **Side:** backend · **Phase:** P5 · **Priority:** Should · **Covers:** D18
- **Depends on:** to-do API (planner P5)

**Prompt:**
1. Add `SoftDeletes` to `Todo` (`deleted_at`). `DELETE /api/v1/todos/{id}` soft-deletes → 204.
2. `POST /api/v1/todos/{id}/restore` → 200 with the resource. Allowed for 7 days after deletion; after that 410.
3. Scheduled command `todos:purge` (daily) force-deletes rows deleted more than 7 days ago. Register it in `routes/console.php`.
4. Policies: only the owner can delete or restore (403 otherwise; 404 for a missing id).

**Done when:**
- `php artisan test --filter=TodoDeleteRestoreTest` covers: delete → absent from the list; restore → back with the same id and position; restore after 8 days → 410; another user → 403; the purge command removes only rows older than 7 days.
- Pint passes and `docs/api.md` is updated.

**Files likely touched:** migration, `app/Models/Todo.php`, `app/Http/Controllers/Api/V1/TodoController.php`, `app/Policies/TodoPolicy.php`, `app/Console/Commands/PurgeDeletedTodos.php`, `routes/console.php`, `tests/Feature/TodoDeleteRestoreTest.php`, `docs/api.md`.

---

### DC-15 · Undo toast for deletes
- **Side:** frontend · **Phase:** P5 · **Priority:** Should · **Covers:** D18
- **Depends on:** DC-14, DC-01 (`restore` in the repo)

**Prompt:**
DayCraft deletes had no undo, and some errors were silent (`02…` D12, D18).
1. `src/components/undo/useUndoableDelete.ts`: remove the item from the cache optimistically, call `repo.remove(id)`, show a toast `t('common.deleted')` with an **Undo** button for 5 s. Undo → `repo.restore(id)` and put it back in the cache. On a remove error → restore the cache and toast the error.
2. Use it for to-dos now. Export it so notes and sessions reuse it later (DC-41, DC-53).
3. The toast is keyboard reachable (focusable Undo button, `aria-live="polite"`).

**Done when:**
- Vitest: delete then Undo calls `restore` once and the row reappears; no undo within 5 s → the row stays gone; a remove error rolls back.
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/components/undo/useUndoableDelete.ts(+test)`, `src/components/toast/*`, `src/features/todos/TodoRow.tsx`, `src/i18n/locales/en.json`.

---

### DC-16 · Today list: no silent caps
- **Side:** frontend · **Phase:** P7 · **Priority:** Must · **Covers:** D13
- **Depends on:** Today screen S3 (planner P7)

**Prompt:**
DayCraft truncated lists at 6 or 20 items with a "+N more…" that linked nowhere (`02…` D8).
1. Today renders **every** to-do for the day in groups: Overdue (first), Today, Done (collapsed by default with a count header that expands).
2. Each group header shows its count. If any group exceeds 50 items, render with windowing (`@tanstack/react-virtual`) instead of truncating.
3. Any compact widget elsewhere that shows a subset must include a "See all (N)" link to the full list.

**Done when:**
- Vitest renders 75 to-dos (10 overdue, 40 today, 25 done) and all 75 are reachable: overdue and today visible, done after expanding, with group counts 10/40/25.
- A test ensures no element contains the text pattern `/\+\d+ more/` without a link.
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/features/today/TodayTodoList.tsx(+test)`, `src/features/today/TodoGroup.tsx`, `package.json` (react-virtual if not present), `src/i18n/locales/en.json`.

---

### DC-17 · Streak service (server, local-date, at-risk)
- **Side:** backend · **Phase:** P7 · **Priority:** Must · **Covers:** D2, D9
- **Depends on:** DC-10, to-do `done_at` (planner P5)

**Prompt:**
Loomora rule (your R1): **a day counts if the user checked off ≥ 1 to-do that local day.** DayCraft counted any timer run, used UTC keys, showed 0 until you acted today, and computed rewards on the client (`02…` D9, D7).
1. `App\Services\StreakService::for(User $u): array{current:int, best:int, today_done:bool, at_risk:bool, last_active_date:?string}`:
   - Active dates = distinct `LocalDate::forUser($u, done_at)` for to-dos with `done_at` not null and not soft-deleted.
   - `current`: if today is active, count back from today. Else if yesterday is active, count back from yesterday and set `at_risk = true`. Else 0.
   - `best`: longest run ever.
2. `GET /api/v1/stats/streak` returns that object. Cache per user for 60 s; bust the cache on to-do toggle.
3. Un-checking the only check-off of today removes today from active days.

**Done when:** `php artisan test --filter=StreakServiceTest` passes, reading cases from `tests/Fixtures/streak-cases.json`. The same file is copied to the frontend in DC-18. Cases must include:
- empty → 0
- today only → 1
- yesterday + today → 2
- yesterday only → 1 with `at_risk = true`
- gap → resets
- check-off at 23:50 local in `Asia/Kolkata` counts for that local day
- an un-check removes the day
- `best` above `current`

Pint passes and `docs/api.md` is updated.

**Files likely touched:** `app/Services/StreakService.php`, `app/Http/Controllers/Api/V1/StatsController.php`, `tests/Fixtures/streak-cases.json`, `tests/Feature/StreakServiceTest.php`, `docs/api.md`.

---

### DC-18 · Streak on the client (guest calc + header display)
- **Side:** frontend · **Phase:** P7 · **Priority:** Must · **Covers:** D2, D9
- **Depends on:** DC-17, DC-11

**Prompt:**
1. `src/features/streak/computeStreak.ts`: a pure TS port of the DC-17 algorithm over the Dexie to-dos (guest mode only), using `localDate()` from DC-11.
2. `useStreak()`: account → `GET /stats/streak`; guest → `computeStreak`. Both return the same shape.
3. Header (S3): flame/count badge. `at_risk` → an "at risk" visual state plus `t('streak.atRisk')` ("Check off one to-do today to keep your N-day streak"). `today_done` → filled state. Never show 0 when `at_risk` is true.
4. Accessible label: `aria-label={t('streak.aria', {count})}`.

**Done when:**
- Vitest runs `computeStreak` against `src/features/streak/__fixtures__/streak-cases.json` (identical copy of the backend fixture) and every case matches.
- A component test renders the at-risk state.
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/features/streak/{computeStreak,useStreak}.ts(+tests)`, `src/features/streak/__fixtures__/streak-cases.json`, `src/features/today/TodayHeader.tsx`, `src/i18n/locales/en.json`.

---

### DC-19 · Daily intention per date — API
- **Side:** backend · **Phase:** P7 · **Priority:** Should · **Covers:** M16, D7
- **Depends on:** DC-10

**Prompt:**
DayCraft's "Today's Goal" text was one localStorage string, never reset and never synced (`02…` D8).
1. Table `daily_intentions`: `user_id`, `local_date` (date), `text` (≤ 200 chars), timestamps, unique `(user_id, local_date)`.
2. `GET /api/v1/days/{date}/intention` → 200 `{date, text}` (text `null` if none). `PUT` the same path `{text}` → upsert. An empty string deletes the row. `{date}` must be `Y-m-d` and within ±366 days of the user's today (else 422).

**Done when:** `php artisan test --filter=DailyIntentionTest` covers: put then get returns the text; a second put updates (still 1 row); an empty string deletes; a bad date → 422; another user's data is invisible. Pint passes and `docs/api.md` is updated.

**Files likely touched:** migration, `app/Models/DailyIntention.php`, `app/Http/Controllers/Api/V1/DailyIntentionController.php`, `tests/Feature/DailyIntentionTest.php`, `docs/api.md`.

---

### DC-20 · Daily intention on Today
- **Side:** frontend · **Phase:** P7 · **Priority:** Should · **Covers:** M16, D7
- **Depends on:** DC-19, DC-01 (add a `dailyIntentions` repo keyed by date)

**Prompt:**
1. Today header area: a one-line field with placeholder `t('today.intentionPlaceholder')`, max 200 characters. Save 600 ms after the last keystroke and on blur. A small "Saved" hint after success.
2. The value is per local date: switching to another day (Week/day views) shows that day's intention. A new day starts empty (no carry-over).
3. Guest → Dexie `dailyIntentions`; account → API. Include it in the DC-04 import payload.

**Done when:**
- Vitest with fake timers: typing debounces into one save; changing the date loads a different value; a save error shows a toast and keeps the text.
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/features/today/DailyIntention.tsx(+test)`, `src/lib/repo/*`, `src/features/auth/migrateGuestData.ts`, `src/i18n/locales/en.json`.

---

### DC-21 · Error boundary, 404, backend-unreachable banner
- **Side:** frontend · **Phase:** P8 · **Priority:** Must · **Covers:** M24, D1
- **Depends on:** app shell (planner)

**Prompt:**
DayCraft showed a raw "Failed to fetch" and nothing else when its backend died (Observed).
1. Route-level error boundary: friendly message `t('error.title')`, "Try again" (resets the boundary and refetches) and "Go to Today". Report to Sentry if configured.
2. A 404 route (S13) for unknown paths.
3. `src/lib/api/health.ts`: in account mode, when a request fails with a network error or 5xx, set `apiStatus = 'down'` and show a top banner `t('offline.banner')` ("Can't reach Loomora. Changes are saved on this device and will sync.") plus a Retry button. Poll `GET /api/v1/health` every 30 s while down; hide the banner on recovery. Also react to `navigator.onLine` / `offline` events.
4. Map axios errors to user text: network → `t('error.network')`, 401 → sign-out flow, 422 → field errors, 429 → `t('error.rateLimited')`, 5xx → `t('error.server')`. Never show raw `error.message`.

**Done when:**
- Playwright `e2e/backend-down.spec.ts`: in account mode with `/api/**` aborted, the banner appears; un-abort and trigger Retry → the banner disappears; an unknown URL shows the 404 page.
- Vitest covers the error-mapping table.
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/app/ErrorBoundary.tsx`, `src/app/NotFoundPage.tsx`, `src/lib/api/{health,errors}.ts(+tests)`, `src/components/OfflineBanner.tsx`, `src/app/routes.tsx`, `e2e/backend-down.spec.ts`, `src/i18n/locales/en.json`.

> Note: "sync later" for account-mode writes while offline is **not** in this task. If it's wanted, the planner adds an outbox task. Until then the banner copy must not promise sync. Use `t('offline.bannerNoSync')` and note it in PROGRESS.

---

### DC-22 · Loading skeletons + empty states for R1 screens
- **Side:** frontend · **Phase:** P8 · **Priority:** Must · **Covers:** M25
- **Depends on:** R1 screens exist (Today, Week, Profile)

**Prompt:**
The screen map requires loading, empty, error, and offline states on every screen (S-map "States every screen needs").
1. A `Skeleton` component (B4 tokens, honors reduced motion) and an `EmptyState` component (icon, title, body, primary action).
2. Wire them into Today (empty = S2 with two equal buttons "Add a to-do" / "Add to my schedule"), the Week day list (empty → "Add to my schedule"), the Today to-do list with no to-dos but schedule items, Profile loading, and the History placeholder.
3. Every query uses an `isPending` → Skeleton, `isError` → inline error with Retry, and empty → EmptyState pattern. Add a tiny helper `QueryState` so screens don't repeat it.

**Done when:**
- Vitest for `QueryState` covers pending, error with retry, empty, and data.
- Playwright `e2e/empty-states.spec.ts` (guest, fresh device): Today shows both S2 buttons; Week shows its empty state.
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/components/{Skeleton,EmptyState,QueryState}.tsx(+tests)`, `src/features/{today,week,profile}/*.tsx`, `src/i18n/locales/en.json`, `e2e/empty-states.spec.ts`.

---

### DC-23 · Auth and nav accessibility basics
- **Side:** frontend · **Phase:** P8 · **Priority:** Must · **Covers:** D21
- **Depends on:** auth screens, nav

**Prompt:**
DayCraft's password fields lacked `autocomplete` (Observed browser warning), and it used clickable `div`s and color-only active states (Static).
1. Auth inputs: `autocomplete` `email` / `current-password` / `new-password`; `name` and labels linked via `htmlFor`; error text linked with `aria-describedby`.
2. Nav (bottom bar + sidebar): real `<a>`/`<button>`, `aria-current="page"` on the active item, plus a non-color active indicator (underline/border weight).
3. All interactive elements are reachable by keyboard and show a visible focus ring (B2 token). No `onClick` on non-interactive elements. Enable `eslint-plugin-jsx-a11y` (recommended) and fix the violations.
4. Add `@axe-core/playwright` and scan `/`, `/signin`, `/signup`, `/today`, `/week`, `/profile`.

**Done when:**
- `npm run lint` passes with jsx-a11y enabled.
- `npx playwright test e2e/a11y.spec.ts` reports 0 serious/critical axe violations on the 6 pages.
- A Playwright check that the password input has `autocomplete="current-password"` on `/signin`.

**Files likely touched:** `src/features/auth/*`, `src/components/nav/*`, `.eslintrc.*`, `package.json`, `e2e/a11y.spec.ts`.

---

## 3. R3 tasks (Focus)

### DC-30 · `sessions` table: single source of tracked time
- **Side:** backend · **Phase:** P10 · **Priority:** Must · **Covers:** D3, D19
- **Depends on:** DC-10, to-dos and schedule items exist

**Prompt:**
DayCraft wrote every focus session twice (`focus_sessions` + `timer_runs`) and back-computed `started_at`. Its history then double-counted (`02…` D3, B5/B9). Loomora has one table.
1. Migration `sessions`:
   - `id uuid pk` (client-supplied), `user_id`
   - `kind enum(focus,countdown,stopwatch)`, `todo_id uuid null`, `schedule_item_id uuid null`, `label string(120) null`
   - `started_at`, `ended_at` (timestamptz), `active_seconds int` (excludes pauses and breaks), `planned_seconds int null`, `completed bool`
   - `segments jsonb` (array of `{phase: focus|short_break|long_break|run, started_at, ended_at, active_seconds}`), `laps jsonb null` (array of ms ints), `preset jsonb null` (snapshot), `local_date date` (computed server-side from `started_at` via `LocalDate`)
   - soft deletes; indexes `(user_id, local_date)`, `(user_id, started_at desc)`
2. `POST /api/v1/sessions` (idempotent by id: a repeat POST with the same id → 200 with the existing row), `GET /api/v1/sessions/{id}`, `DELETE` (soft), `POST …/restore`.
3. Validation: `ended_at >= started_at`; `active_seconds <= ended_at - started_at + 5`; the sum of segment `active_seconds` == `active_seconds` (±2); `todo_id`/`schedule_item_id` must belong to the user.
4. **Add no second table** for timer runs. Any history or stat reads `sessions` only.

**Done when:**
- `php artisan test --filter=SessionStoreTest` covers: a valid focus session with 3 segments → 201 with the correct `local_date` for `Asia/Phnom_Penh`; a duplicate POST → 200, still 1 row; inconsistent segments → 422; another user's `todo_id` → 422; soft delete + restore.
- Pint passes and `docs/api.md` is updated.

**Files likely touched:** migration, `app/Models/Session.php` (name the class `FocusSession` or `TrackedSession` if `Session` clashes; record it in DECISIONS.md), `app/Http/Controllers/Api/V1/SessionController.php`, `app/Http/Requests/StoreSessionRequest.php`, `tests/Feature/SessionStoreTest.php`, `docs/api.md`.

---

### DC-31 · Session history API: cursor pagination, filter, search
- **Side:** backend · **Phase:** P10 · **Priority:** Should · **Covers:** M19, D16
- **Depends on:** DC-30

**Prompt:**
DayCraft fetched every row on every visit (`02…` D16).
1. `GET /api/v1/sessions?kind=&q=&from=&to=&cursor=&limit=` → `cursorPaginate` ordered by `started_at desc`. `limit` max 50 (default 30). `q` = case-insensitive `ILIKE` on `label` and on the linked to-do title. `from`/`to` are local dates.
2. The response includes `meta.day_totals` for the days present on the page (`{local_date, count, active_seconds}`), computed by SQL aggregate (not PHP loops).

**Done when:** `php artisan test --filter=SessionHistoryTest` covers:
- 75 sessions → page 1 = 30 with a `next_cursor`; pages chain to cover all 75 exactly once
- `kind=stopwatch` filters
- `q` matches both the label and the to-do title
- `day_totals` are correct and each focus session counts **once**
- `limit=500` → 422

Pint passes and `docs/api.md` is updated.

**Files likely touched:** `app/Http/Controllers/Api/V1/SessionController.php`, `app/Http/Requests/ListSessionsRequest.php`, `app/Http/Resources/SessionResource.php`, `tests/Feature/SessionHistoryTest.php`, `docs/api.md`.

---

### DC-32 · Timer engine store (countdown / stopwatch / focus), persisted
- **Side:** frontend · **Phase:** P10 · **Priority:** Must · **Covers:** M1, D15
- **Depends on:** C0 (Zustand)

**Prompt:**
DayCraft's countdown used a wall-clock `endAt` persisted in localStorage, which was robust (`02…` D4). But its stopwatch and focus sessions lived in component state and were lost on reload (D15). Build one engine for all three.
1. `src/stores/timer.ts` (Zustand + `persist`, key `loomora-timer`). State:
   - `kind`, `status: idle|running|paused|ringing`, `startedAt` (first start, ISO)
   - `runStartedAt: number|null` (epoch ms of the current run), `accumulatedMs`, `durationMs` (countdown/phase), `laps: number[]` (ms, cumulative), `segments`, `label`, `todoId?`, `scheduleItemId?`, `focus?` (plan + current phase index from DC-35)
   - Actions: `start, pause, resume, lap, reset, finishPhase, stopRinging, setLabel, attach(todoId|scheduleItemId)`
2. Elapsed = `accumulatedMs + (running ? Date.now() - runStartedAt : 0)`. Remaining = `durationMs - elapsed`. **Never** count ticks.
3. Rehydration: after reload, a running countdown whose remaining ≤ 0 goes to `ringing` (completion handled by DC-33). A running stopwatch continues with the correct elapsed time.
4. Pure selectors `selectElapsedMs(state, now)` / `selectRemainingMs(state, now)` for tests.
5. Stopwatch display precision is 1/100 s. Laps are stored in ms (DayCraft floored them to seconds).

**Done when:**
- Vitest with `vi.useFakeTimers()` and a mocked `Date.now`:
  - countdown 25 min: pause at 10 min, resume, remaining is correct
  - simulate a reload (serialize/rehydrate the store) mid-run: elapsed preserved within 10 ms
  - expired while "closed" → `ringing` on rehydrate
  - laps keep ms precision
  - reset clears everything
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/stores/timer.ts(+test)`, `src/features/focus/types.ts`.

---

### DC-33 · Root timer watcher + running indicator
- **Side:** frontend · **Phase:** P10 · **Priority:** Must · **Covers:** M1
- **Depends on:** DC-32, DC-34, DC-30, DC-01 (`sessions` repo)

**Prompt:**
DayCraft mounted one watcher at the app root, so a countdown finished on any page (`02…` D4).
1. `src/features/timer/TimerWatcher.tsx`, mounted once in the app root: checks every 500 ms and on `visibilitychange`; when remaining ≤ 0 → `finishPhase()`, play the alarm (DC-34), and show a toast `t('timer.done', {label})`.
2. When a whole run ends (countdown done, stopwatch saved, focus plan finished or ended early), build **one** session payload from the store and save it via `useRepos().sessions.create` (one call; the id is generated when the run starts so retries are idempotent).
3. Running indicator: a small pill in the header/nav ("● 12:34 Focus") on every screen while a timer runs or rings; tap → the timer screen.
4. Update `document.title` with the remaining time while running; restore it after.

**Done when:**
- Vitest with fake timers: completion fires once even with two `visibilitychange` events; exactly one `sessions.create` per run; the indicator renders on a non-timer route.
- Playwright `e2e/timer-across-pages.spec.ts` (guest): start a 5 s countdown, navigate to Week, wait 6 s; toast shown, one session in Dexie.
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/features/timer/{TimerWatcher,RunningPill}.tsx(+tests)`, `src/features/timer/buildSessionPayload.ts(+test)`, `src/app/App.tsx`, `src/i18n/locales/en.json`, `e2e/timer-across-pages.spec.ts`.

---

### DC-34 · Audio service: unlock, mute, synth sounds, exact repeat
- **Side:** frontend · **Phase:** P10 · **Priority:** Must · **Covers:** M12, D8
- **Depends on:** C0

**Prompt:**
Mobile browsers block audio until a user gesture. DayCraft resumed its AudioContext on the first click/key/touch (`02…` D5). Its "Repeat (3x)" played 4 times (D8). Design **Loomora's own** tones; don't reuse DayCraft's frequencies.
1. `src/lib/audio/alarm.ts`: a singleton with a lazily created `AudioContext`; `unlock()` is registered once on the first `pointerdown`/`keydown` and then removed.
2. At least 2 synthesized sounds (oscillator + gain envelope, ≤ 2 s each). `play(soundId, {mode: 'once'|'repeat'|'loop', times?: number, volume})`. `repeat` plays **exactly `times`** (default 3). `loop` continues until `stop()`.
3. `src/stores/sound.ts` (persist `loomora-sound`): `muted`, `soundId`, `mode`, `volume` (0–1). When muted, `play` is a no-op, but the UI still shows the ringing state with a Stop button.
4. Respect `document.hidden`: if the context is suspended and can't resume, fall back to the `Notification` API **only if permission was already granted** (never prompt here).

**Done when:**
- Vitest with a mocked `AudioContext` (oscillator start-count spy): `repeat` with `times: 3` creates exactly 3 starts; `loop` keeps playing until `stop()`; muted creates 0; `unlock()` resumes a suspended context once.
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/lib/audio/{alarm,sounds}.ts(+tests)`, `src/stores/sound.ts`, `src/app/App.tsx` (register unlock), `src/test/mocks/audioContext.ts`.

---

### DC-35 · Focus cycle planner (pure logic)
- **Side:** frontend · **Phase:** P10 · **Priority:** Must · **Covers:** M2, M4
- **Depends on:** none

**Prompt:**
Rules taken from DayCraft (`02…` D7): focus → short break → … → the last focus is followed by a **long break**, then done. DayCraft did not auto-start phases; Loomora adds an opt-in `autoStartNext`.
1. `src/features/focus/plan.ts`:
   - `buildPlan({focusMin, shortMin, longMin, cycles}) → Phase[]`
   - `nextPhase(plan, index)`
   - `estimatedEnd(plan, index, remainingMs, now) → Date` (sum of the remaining current phase + all later phases)
   - `accruedFocusMs(segments)` (only focus segments, **measured**, not the planned length)
   - Breaks of 0 minutes are skipped from the plan.
2. Built-in presets constant: 25/5/15×4, 50/10/20×2, 90/15/30×1 (matches your 25/50/90 countdown set). Labels via `t()`.

**Done when:**
- Vitest: plan for 25/5/15×4 has 8 phases ending with a long break; shortMin 0 removes short breaks; `estimatedEnd` matches a hand-calculated value; `accruedFocusMs` ignores breaks and counts partial focus segments.
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/features/focus/plan.ts(+test)`, `src/features/focus/presets.ts`, `src/i18n/locales/en.json`.

---

### DC-36 · Focus session screen: phases, attach to to-do, end early
- **Side:** frontend · **Phase:** P10 · **Priority:** Must · **Covers:** M2, M4, M5, D12
- **Depends on:** DC-32, DC-33, DC-34, DC-35

**Prompt:**
1. `/focus` screen: choose a preset; optional intention (defaults to the attached to-do or schedule item title); "Attach to…" picker listing today's to-dos and schedule items; Start.
2. Running: phase badge ("Focus · 2 of 4" / "Short break"), big remaining time, progress ring, "Ends ≈ 10:45" (from `estimatedEnd`, formatted with DC-11), Pause/Resume, Skip break, End session.
3. Phase end → ring (DC-34) and an "Up next: Short break 5:00" card with Start; auto-start if the `autoStartNext` setting is on.
4. **End early:** save the session if **total accrued focus ≥ 60 s** (DayCraft judged only the current phase and lost earlier cycles, D12), with `completed=false` and all segments so far. Under 60 s total → discard, with a toast saying so.
5. Completing all phases → `completed=true`. If attached to a to-do, offer "Mark to-do done?" (Yes triggers the DC-13 toggle).
6. Session save goes through DC-33's single-create path.

**Done when:**
- Vitest (component + fake timers) covers:
  - complete a 1-cycle tiny plan (use a test preset of seconds) → one session with `completed=true` and the correct segments
  - end early after 2 completed cycles while 10 s into cycle 3 → saved, `completed=false`, accrued = 2 full focus phases + 10 s
  - end early at 30 s total → nothing saved
  - an attached to-do id is present in the payload
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/features/focus/{FocusPage,FocusRunning,AttachPicker,UpNextCard}.tsx(+tests)`, `src/stores/timer.ts`, `src/i18n/locales/en.json`.

---

### DC-37 · Full-screen focus view
- **Side:** frontend · **Phase:** P10 · **Priority:** Should · **Covers:** M7
- **Depends on:** DC-36

**Prompt:**
1. A "Full screen" button on the running focus screen: request the Fullscreen API on the root element. If unsupported (iOS Safari), render a fixed overlay (`inset-0`, z above nav) instead.
2. The view shows only the remaining time, phase, intention, and Pause/End. `Esc` or Exit leaves.
3. Request a Screen Wake Lock (`navigator.wakeLock.request('screen')`) while running in this view when supported; release it on pause or exit.

**Done when:**
- Vitest: with `requestFullscreen` undefined, the overlay renders; with a mocked wake lock, request on enter and release on exit; `Esc` exits.
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/features/focus/FullScreenFocus.tsx(+test)`, `src/lib/browser/{fullscreen,wakeLock}.ts`.

---

### DC-38 · Focus presets API
- **Side:** backend · **Phase:** P10 · **Priority:** Should · **Covers:** M3
- **Depends on:** C0

**Prompt:**
DayCraft stored custom presets as a JSON blob on the profile with client-only validation (`02…` D7).
1. Table `focus_presets`: `id uuid` (client), `user_id`, `name string(40)`, `focus_minutes 1–180`, `short_break_minutes 0–60`, `long_break_minutes 0–90`, `cycles 1–12`, timestamps, soft deletes. Unique `(user_id, lower(name))` among non-deleted rows.
2. CRUD `GET/POST/PATCH/DELETE /api/v1/focus-presets`. Max 20 per user (422 beyond that). Include the table in the DC-03 import payload (`focus_presets`).

**Done when:** `php artisan test --filter=FocusPresetTest` covers: create/list/update/delete; a duplicate name with different case → 422; focus 0 → 422; cycles 13 → 422; the 21st preset → 422; another user → 403/404; the import upserts presets. Pint passes and `docs/api.md` is updated.

**Files likely touched:** migration, `app/Models/FocusPreset.php`, `app/Http/Controllers/Api/V1/FocusPresetController.php`, `app/Http/Requests/*FocusPreset*`, `app/Services/GuestImportService.php`, `tests/Feature/FocusPresetTest.php`, `docs/api.md`.

---

### DC-39 · Focus presets UI
- **Side:** frontend · **Phase:** P10 · **Priority:** Should · **Covers:** M3
- **Depends on:** DC-38, DC-36, DC-01 (add a `focusPresets` repo + Dexie table version bump)

**Prompt:**
1. On `/focus`: a "My presets" list under the built-ins with Create / Edit / Delete (with undo via DC-15).
2. Bottom-sheet form: name, focus, short break, long break, cycles (number steppers). Validation is the same as DC-38 with messages via `t()`. Show a live preview line ("4 × 25 min focus · ends with 15 min break · total 2 h 0 m").
3. Deleting the selected preset falls back to the first built-in. Editing the selected preset updates the selection if idle; if a session is running, changes apply next time (show a note).

**Done when:**
- Vitest: a validation error shows the right messages; create appears in the list; delete → undo restores it; editing while running doesn't change the running plan.
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/features/focus/{PresetList,PresetSheet}.tsx(+tests)`, `src/lib/db/schema.ts` (version 2), `src/lib/repo/*`, `src/i18n/locales/en.json`.

---

### DC-40 · Sound settings: picker, preview, volume, play mode
- **Side:** frontend · **Phase:** P10 · **Priority:** Should · **Covers:** M9, M10
- **Depends on:** DC-34

**Prompt:**
1. In Profile → "Timer sound": the mute toggle (already planned), a sound list with Preview buttons, a volume slider (0–100 %, step 5), and play mode (Once / Repeat 3× / Until I stop).
2. "Test alarm" plays the current choice in the current mode, and becomes "Stop" while playing.
3. Account users: save these prefs to `PATCH /api/v1/me/preferences` (add fields `sound_id`, `sound_mode`, `sound_volume`, `sound_muted` in the backend task or here if trivial; if the backend lacks them, keep the prefs local and note it in PROGRESS). Apply them at app start, not only when Settings opens (DayCraft applied cloud settings only on its settings page, `02…` D5).

**Done when:**
- Vitest: preview calls `play(id, {mode:'once'})`; changing volume persists to the store; the test button toggles to Stop; account mode with mocked `/me` applies the prefs on boot.
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/features/profile/SoundSettings.tsx(+test)`, `src/stores/sound.ts`, `src/app/bootstrap.ts`, `src/i18n/locales/en.json`.

---

### DC-41 · History screen: filters, search, delete + undo
- **Side:** frontend · **Phase:** P10 · **Priority:** Should · **Covers:** M19, D18
- **Depends on:** DC-31, DC-15, DC-30

**Prompt:**
1. `/focus/history`: chips All / Focus / Countdown / Stopwatch; a search box (debounced 300 ms); an infinite list (TanStack `useInfiniteQuery` over the cursor API, or Dexie paging for guests) grouped by local date with "Today / Yesterday / weekday, date" headers and the day total from `meta.day_totals`.
2. Row: icon by kind, label (or linked to-do title), duration `formatDuration`, local start time, a "✓ completed" mark for focus. Tap → a detail sheet with segments and laps.
3. Delete with undo (DC-15).
4. Header total = sum of the loaded `day_totals` (labeled "Shown: X"). No double counting because there is one table.

**Done when:**
- Vitest: filter chips change the query key; search debounces; delete → undo restores the row; group headers use the user's timezone (test with `Asia/Phnom_Penh` vs `America/Los_Angeles` on the same UTC instants).
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/features/history/{HistoryPage,SessionRow,SessionDetailSheet}.tsx(+tests)`, `src/lib/api/sessions.ts`, `src/i18n/locales/en.json`.

---

## 4. R4 tasks (Review)

### DC-50 · Stats summary API (local-date buckets, focus vs tracked)
- **Side:** backend · **Phase:** P11 · **Priority:** Must · **Covers:** D2, D4, D16, M20
- **Depends on:** DC-30, DC-17

**Prompt:**
DayCraft labeled the total of all timer kinds "Total focus time", bucketed charts by UTC day, and loaded all rows into the browser (`02…` D11).
1. `GET /api/v1/stats/summary?from=YYYY-MM-DD&to=YYYY-MM-DD` (max 92 days):
   - `days: [{local_date, focus_seconds, tracked_seconds, todos_done, todos_due}]` with **every** date in the range (zeros filled)
   - `totals: {focus_seconds, tracked_seconds, sessions, focus_sessions_completed, focus_completion_rate, todos_done}`
   - `by_kind: {focus, countdown, stopwatch}` (seconds)
   - `streak` (from DC-17)
2. All aggregation in SQL grouped by `local_date` (stored on sessions; for to-dos use `LocalDate` on `done_at` via a SQL `AT TIME ZONE` expression with the user's tz). Cache 60 s per user and range.
3. `focus_seconds` counts **only** `kind=focus` active seconds; `tracked_seconds` counts all kinds.

**Done when:** `php artisan test --filter=StatsSummaryTest` covers:
- a 7-day range returns 7 rows including zero days
- a session at 23:30 local in `Asia/Kolkata` lands on that local date
- focus vs tracked are separated correctly
- the completion rate is rounded the same as documented
- a range of 93 days → 422
- the query count stays ≤ 5 (assert with `DB::enableQueryLog`)

Pint passes and `docs/api.md` is updated.

**Files likely touched:** `app/Http/Controllers/Api/V1/StatsController.php`, `app/Services/StatsService.php`, `app/Http/Requests/StatsSummaryRequest.php`, `tests/Feature/StatsSummaryTest.php`, `docs/api.md`.

---

### DC-51 · Analytics KPIs + method share
- **Side:** frontend · **Phase:** P11 · **Priority:** Should · **Covers:** D4, M20
- **Depends on:** DC-50 (guest: compute the same shape from Dexie in `src/features/insights/computeSummary.ts`)

**Prompt:**
1. Insights screen KPI tiles with **honest labels**: "Focus time", "Tracked time", "Focus sessions (X completed)", "Completion rate", "Current streak / best".
2. A "Time by mode" chart (focus/countdown/stopwatch) from `by_kind`, accessible: each segment also listed as text with % and duration (don't rely on color alone).
3. Range switcher: 7 / 30 days.
4. Guest: `computeSummary()` produces the identical shape from Dexie. Test it against a shared fixture with expected output (reuse the DC-50 cases as JSON in both repos: `stats-summary-cases.json`).

**Done when:**
- Vitest: `computeSummary` matches the fixture; tiles render the correct labels and values; the text list equals the chart data.
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/features/insights/{InsightsPage,KpiTiles,TimeByMode}.tsx(+tests)`, `src/features/insights/computeSummary.ts(+test)`, `src/features/insights/__fixtures__/stats-summary-cases.json`, `src/i18n/locales/en.json`.

---

### DC-52 · Notes API: tags, pin, color, search, soft delete
- **Side:** backend · **Phase:** P11 · **Priority:** Should · **Covers:** M21, D18
- **Depends on:** C0. Attaching a note to a to-do is a Loomora-only planner task; include a nullable `todo_id` now.

**Prompt:**
Behavior from DayCraft (`02…` D12): title or body required, tags lower-cased with `#` stripped and deduped, pinned first then most recently updated, search over title/body/tags. Fix: DayCraft relied on an unverified DB trigger for `updated_at` and ignored pin/delete errors.
1. Table `notes`: `id uuid` (client), `user_id`, `todo_id null`, `title string(120) null`, `body text null` (≤ 10,000), `tags jsonb` (≤ 10 tags, each ≤ 24 chars), `pinned bool`, `color` (enum of the Loomora palette keys, nullable), timestamps (Eloquent manages `updated_at`), soft deletes.
2. CRUD + `POST /notes/{id}/restore` + `GET /notes?q=&tag=&pinned=&cursor=` ordered `pinned desc, updated_at desc`. Normalize tags server-side.
3. 422 if both title and body are empty.

**Done when:** `php artisan test --filter=NoteTest` covers: tag normalization (`#Work`, `work ` → `["work"]`); empty note → 422; order is pinned first; `q` matches tag/body/title; delete + restore; another user's note → 404/403; the import (DC-03) includes notes. Pint passes and `docs/api.md` is updated.

**Files likely touched:** migration, `app/Models/Note.php`, `app/Http/Controllers/Api/V1/NoteController.php`, `app/Http/Requests/*Note*`, `app/Services/GuestImportService.php`, `tests/Feature/NoteTest.php`, `docs/api.md`.

---

### DC-53 · Notes UI: tags, pin, color, search, undo
- **Side:** frontend · **Phase:** P11 · **Priority:** Should · **Covers:** M21, D18
- **Depends on:** DC-52, DC-15

**Prompt:**
1. Notes list: search box (title/body/tags), tag chips filter, pinned section first. Every pin/delete error shows a toast (DayCraft swallowed them).
2. Note sheet: title, body, tag input (Enter or comma adds; normalized like the server; × removes), color swatches from the Loomora palette (with text names for accessibility), pin toggle, Save. Empty title and body → inline error.
3. Delete with undo (DC-15). Guest uses Dexie with the same normalization function (`src/features/notes/normalizeTags.ts`, unit-tested against the same cases as the backend).

**Done when:**
- Vitest: `normalizeTags` cases; search filters; a pin failure rolls back and toasts; delete → undo restores the note.
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/features/notes/{NotesPage,NoteSheet,TagInput,normalizeTags}.tsx|ts(+tests)`, `src/i18n/locales/en.json`.

---

### DC-54 · Today insight sentence
- **Side:** frontend · **Phase:** P11 · **Priority:** Later · **Covers:** M17
- **Depends on:** Today progress ring (planner P7)

**Prompt:**
DayCraft showed one encouraging sentence by progress band (`02…` D8). Write Loomora's own copy.
1. `src/features/today/insight.ts`: `pickInsight({done, total, overdue, hour})` → i18n key. Bands: no to-dos, none done, < 50 %, 50–99 %, all done, plus an "overdue present" variant. Make it deterministic (no randomness, so tests are stable).
2. Show it under the progress ring. Respect `aria-live="polite"` so updates are announced after a check-off.

**Done when:**
- Vitest table test covers every band and the overdue variant.
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/features/today/{insight.ts,TodayProgress.tsx}(+tests)`, `src/i18n/locales/en.json`.

---

## 5. Later tasks (after R4)

### DC-60 · Achievements computed on the server
- **Side:** backend · **Phase:** P12+ · **Priority:** Later · **Covers:** M18, D9

**Prompt:**
DayCraft's browser decided achievements and inserted its own `user_achievements` rows (forgeable; RLS **Unverified**, `02…` D7/D9). Loomora awards them only on the server.
1. A config file `config/achievements.php` (code-defined catalog: key, name i18n key, metric `streak_best|focus_hours|todos_done|sessions_completed`, threshold). Table `user_achievements (user_id, key, achieved_at)`, unique `(user_id, key)`.
2. `AchievementService::evaluate(User)` runs in a queued job dispatched after a to-do toggle or a session store. It inserts newly met keys only.
3. `GET /api/v1/achievements` → the catalog with `achieved_at|null`. **No** client write endpoint exists.

**Done when:** `php artisan test --filter=AchievementTest` covers: reaching a threshold awards once; re-evaluating doesn't duplicate; there's no POST route (`route:list` assertion); un-checking doesn't revoke (documented choice). Pint passes and `docs/api.md` is updated.

**Files likely touched:** `config/achievements.php`, migration, `app/Services/AchievementService.php`, `app/Jobs/EvaluateAchievements.php`, `app/Http/Controllers/Api/V1/AchievementController.php`, `tests/Feature/AchievementTest.php`, `docs/api.md`.

---

### DC-61 · File storage for avatar and custom sound
- **Side:** backend · **Phase:** P12+ · **Priority:** Later · **Covers:** M11, M13, D10

**Prompt:**
DayCraft stored the avatar and custom audio as base64 data-URLs in DB rows and localStorage (`02…` D10/D14).
1. `POST /api/v1/me/avatar` (image jpeg/png/webp ≤ 2 MB; resize to 256 px server-side with GD/Imagick; store on the `public` disk or S3 per `FILESYSTEM_DISK`); `DELETE` removes it. `users.avatar_path`, exposed as `avatar_url` in `/me`.
2. `POST /api/v1/me/sound` (audio mp3/ogg/wav ≤ 1 MB, duration ≤ 10 s if detectable); `DELETE`. `users.custom_sound_path`.
3. Old files are deleted on replace and on account deletion (extend the delete-account job).

**Done when:** `php artisan test --filter=UserMediaTest` with `Storage::fake` covers: valid upload stored and URL returned; too big → 422; wrong MIME → 422; replace deletes the old file; account deletion removes the files. Pint passes and `docs/api.md` is updated.

**Files likely touched:** migration, `app/Http/Controllers/Api/V1/UserMediaController.php`, `app/Http/Requests/*Media*`, `app/Jobs/DeleteAccount*.php`, `tests/Feature/UserMediaTest.php`, `docs/api.md`.

---

### DC-62 · Custom alarm sound upload UI
- **Side:** frontend · **Phase:** P12+ · **Priority:** Later · **Covers:** M11
- **Depends on:** DC-61, DC-34, DC-40

**Prompt:**
1. In Sound settings: "Upload your own" (account only; guests see `t('sound.uploadNeedsAccount')`). Client checks ≤ 1 MB and an audio MIME before upload, with progress, Preview, and Remove.
2. `alarm.ts` supports `soundId: 'custom'` by loading the URL into an `AudioBuffer` once (cached). Repeat counts follow DC-34 exactly.
3. Never store audio bytes in localStorage (only the URL).

**Done when:**
- Vitest: an oversize file is rejected before upload; a successful upload sets `soundId='custom'`; `repeat` with `times:3` on a custom buffer starts 3 times (mocked).
- Lint, typecheck, and tests pass.

**Files likely touched:** `src/features/profile/SoundSettings.tsx`, `src/lib/audio/alarm.ts`, `src/lib/api/media.ts`, tests, `src/i18n/locales/en.json`.

---

### DC-63 · Recent laps card
- **Side:** frontend · **Phase:** P12+ · **Priority:** Later · **Covers:** M27
- **Depends on:** DC-32, DC-30

**Prompt:**
On the Focus home, show the laps of the current stopwatch run, or the latest saved stopwatch session: lap number, split, and cumulative time (DayCraft's dashboard showed cumulative only, `02…` D6). Empty state via `EmptyState`.

**Done when:** Vitest: splits computed correctly from cumulative ms; falls back to the last saved session; empty state renders. Lint, typecheck, and tests pass.

**Files likely touched:** `src/features/focus/RecentLaps.tsx(+test)`.

---

### DC-64 · Public SEO pages: sitemap, meta, JSON-LD
- **Side:** frontend · **Phase:** P12+ · **Priority:** Later · **Covers:** M23

**Prompt:**
DayCraft's public surface had per-page titles and descriptions, OG/Twitter tags, `SoftwareApplication` JSON-LD, a sitemap, and robots (`01…` §1, Observed sitemap). Loomora is a Vite SPA, so pre-render the public routes.
1. Add per-route `<title>`/meta via a small head manager (`react-helmet-async` or equivalent) for `/`, `/privacy`, `/terms`.
2. Pre-render the public routes at build time (`vite-plugin-prerender` or a Playwright-based script in `scripts/prerender.mjs`); app routes stay client-only.
3. `public/sitemap.xml` generated at build from a route list; `public/robots.txt`; JSON-LD `WebApplication` on `/` (price 0).

**Done when:**
- `npm run build` outputs `dist/index.html`, `dist/privacy/index.html`, and `dist/terms/index.html` containing their own `<title>`.
- `dist/sitemap.xml` lists exactly the 3 public URLs.
- A Vitest/node test validates that the JSON-LD parses as JSON.
- Lint and typecheck pass.

**Files likely touched:** `src/app/Head.tsx`, `scripts/{prerender,sitemap}.mjs`, `public/robots.txt`, `vite.config.ts`, `package.json`.

---

## 6. Coverage check

| Gap item | Task(s) |
|---|---|
| M1 global timer | DC-32, DC-33 |
| M2 focus cycles | DC-35, DC-36 |
| M3 custom presets | DC-38, DC-39 |
| M4 intention + est. end | DC-35, DC-36 |
| M5 end early keeps time | DC-36 |
| M6 password reset | DC-05, DC-06 |
| M7 full-screen focus | DC-37 |
| M8 clock (12/24 h part) | DC-10, DC-11 (clock page itself: not tasked, Later) |
| M9 sound picker/volume | DC-34, DC-40 |
| M10 play modes | DC-34, DC-40 |
| M11 custom sound | DC-61, DC-62 |
| M12 audio unlock | DC-34 |
| M13 avatar | DC-61 (UI: add when the profile design includes an avatar) |
| M14 reset guest data | DC-08 |
| M15 sign-out/exit confirm | DC-07 |
| M16 daily intention | DC-19, DC-20 |
| M17 insight sentence | DC-54 |
| M18 achievements | DC-60 |
| M19 history filters/search/delete | DC-31, DC-41 |
| M20 method share + completion rate | DC-50, DC-51 |
| M21 notes tags/pin/color/search | DC-52, DC-53 |
| M22 landing guest CTA | DC-09 |
| M23 SEO pages | DC-64 |
| M24 error/404/backend-down | DC-21 |
| M25 empty/loading states | DC-22 |
| M26 optimistic check-off | DC-13 |
| M27 recent laps | DC-63 |
| D1 | DC-01, DC-02, DC-09, DC-21 |
| D2 | DC-10, DC-11, DC-17, DC-18, DC-50 |
| D3 | DC-30, DC-41 |
| D4 | DC-50, DC-51 |
| D5 | DC-01 |
| D6 | DC-03, DC-04 |
| D7 | DC-19, DC-20 (+ planner P5: to-dos with `due_date`, overdue carry-over) |
| D8 | DC-34 |
| D9 | DC-17, DC-18, DC-60 |
| D10 | DC-61, DC-62 |
| D11 | n/a (no SSR in Loomora) |
| D12 | DC-36 |
| D13 | DC-16 |
| D14 | DC-08 |
| D15 | DC-32 |
| D16 | DC-31, DC-50 |
| D17 | DC-12 |
| D18 | DC-13, DC-14, DC-15, DC-41, DC-53 |
| D19 | DC-30 |
| D20 | review rule (no half-built entities); no task |
| D21 | DC-23 |

**Still Unverified from DayCraft (no task depends on them being true):** DayCraft's RLS policies, DB triggers, email-confirmation setting, achievement catalog, and real API responses. Loomora tasks specify their own server rules instead of copying any of these.
