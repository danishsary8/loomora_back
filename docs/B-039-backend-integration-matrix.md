# B-039 Backend integration matrix

**Status** — local integration passed and backend evidence is ready for planner review. **Production release remains NO-GO.** Hosted staging is blocked on Q25 and QA-009. Q23 remains open for the planner.

## Source and environment

| Evidence | Source |
|---|---|
| Backend candidate | `danishsary8/loomora_back` at `1188595d4aa0b3c5ab2638b4ee6db0d2829640e7` (B-037, PR #40). OpenAPI Git blob `27ad5041baa54035503367419d46978f5723c2d5`. |
| Frontend snapshot | `danishsary8/Loomora_front` at `98a9b79c155a9c2bb23330245687bad4a6b859b7`, fetched and inspected with `git show`. OpenAPI Git blob `ba9d006e21cc70118f23ccb39a08a36e53bbb7e3`. |
| HTTP service | Repository Dockerfile built from the exact backend Git archive, PHP 8.3.37 and Apache. Normal entrypoint built both config and route caches and applied all 16 migrations in batch 1. `APP_ENV=production`, debug off. The loopback probe made real Apache requests over TCP. |
| Database and network | PostgreSQL 16.16 with disposable RAM-backed databases for HTTP and Pest. An internal-only Docker network without an external runtime route held the API and databases. The approved production frontend origin string was configured for CORS assertions. No requests went to that origin or to any deployed services. |
| Providers | Laravel log mailer and synthetic `.test` sender/recipients. OAuth provider responses in existing Pest tests use a mock. The separate Google-only HTTP case used SQL-seeded synthetic account and grant fixtures. Mail links, bearer values, signatures, tokens, keys and raw logs are omitted from the evidence. |

## Local integration and code verification

All HTTP status codes below were observed against the production image. The right-hand column identifies the additional checks verified by Pest against the same source revision.

| Scope | Local HTTP outcome | Existing Pest evidence |
|---|---|---|
| Boot and health | **PASS**. Shallow `GET /health` and `?deep=1` returned 200 with the expected bodies. Wrong database refused startup after three migration attempts. A trailing slash on the configured frontend origin stopped startup before Apache. Both guards exited 1. | `HealthTest`, `HardeningTest` and `FrontendOriginTest` cover shallow/deep semantics, invalid canonical configuration and cached configuration. |
| Browser-origin policy | **PASS**. Real GET echoed the exact allowed origin. Allowed `OPTIONS /me` returned 204, accepted PATCH and Authorization and omitted the credentials header. Unrelated origin also returned 204 with no allow-origin grant. On the simulated forwarded HTTPS request, HSTS, CSP, nosniff, DENY framing and no-referrer matched source. Plain HTTP omitted HSTS. | `HardeningTest` covers lookalike origins, trusted-proxy handling and sanitized error details. |
| Signup and password sign-in | **PASS**. `POST /auth/register` 201 and `POST /auth/login` 200 had exactly `{token, expires_at, user}` and 30-day expiry. Duplicate and invalid email, missing name, weak password and wrong password returned 422. `GET /me` 200 returned `{data}` with the original UUID. | `AuthTest` covers validation, server-owned fields, invalid credentials and token lifecycles. |
| Bearer isolation and revocation | **PASS**. Missing, invalid, deliberately expired or logged-out bearer returned 401. `POST /auth/logout` was empty 204 and revoked only that session. Revoked bearer could no longer PATCH the profile. Other account's UUID and data remained accessible to that account. | `CredentialConcurrencyTest` covers mutations waiting behind credential revocation, missing account after deletion, an already-authorized read completing, and subsequent rejection. |
| Google OAuth and exchange | **PASS** for the exercised HTTP checks. Callback without state returned 302 with the canonical `google_failed` redirect. A synthetic grant returned a token 200 once. Reused and reset-invalidated grants returned 422 on `code`. | `GoogleAuthTest` covers verified and unverified provider email, subject precedence and mismatch, account linking, expiry, latest-only grants, deletion and fresh sign-in after reset. `CredentialConcurrencyTest` verifies one successful exchange during overlap and consistent callback/reset/deletion ordering. |
| Verification mail | **PASS**. Registration generated a verification link for the canonical frontend. Resend 200, signed verify 200 with verified user, invalid signature 422, already-verified resend 200 and verify after account deletion 422. Synthetic messages were captured locally. | `EmailAndPasswordTest` checks expiry, stale email, idempotence, no delivery on already-verified resend, throttle, and failure policy. |
| Password recovery | **PASS**. Known and unknown emails gave identical `POST /auth/password/forgot` 200 message bodies. The locally captured reset link used the configured frontend. Redeem 200, reused token 422, old password 422 and old bearer 401. Fresh password sign-in returned 200 and the same user UUID. | `EmailAndPasswordTest`, `PasswordResetAtomicityTest` and `CredentialConcurrencyTest` verify expiration, delivery failure, supersession, rollback and retry, atomic token cleanup and isolation. |
| Google-only recovery and deletion | **PASS** for a synthetic local account. Password sign-in and password-confirmed deletion before recovery both returned 422. Existing forgot/reset returned 200, revoked the old bearer and invalidated a pending grant. Fresh password sign-in returned 200 for the same UUID and the stored Google subject was preserved. Explicit deletion returned empty 204, then old bearer 401. | `GoogleAccountRecoveryTest` covers preservation, invalid or expired recovery credentials and transactional deletion rollback. Q23 retains the launch and assurance decision. |
| Profile, survey and deletion | **PASS**. `PATCH /me` and `POST /me/survey` returned 200 with resource data. Invalid timezone and wrong deletion password returned 422. Password-confirmed deletion was empty 204. Direct disposable SQL confirmed removal of the user, bearer tokens, to-dos, schedules, intentions, linked feedback, exceptions and check-ins. Guest feedback and the other account survived. The reset token was redeemed earlier in the flow, and SQL found no reset row after deletion. | `GoogleAccountRecoveryTest` and `PasswordResetAtomicityTest` verify deletion removes a pending reset row. `FeedbackAndAccountTest` and `GoogleAccountRecoveryTest` observe `DELETE /me` returning 500 on a forced failure and verify rollback. `TodoRestoreTest` covers removal of soft-deleted history. |
| To-dos and reminders | **PASS**. Empty Today list was `{data: []}`. Creation 201, nested subtasks, notes edit 200, due/dismiss/snooze 200, completion and undo 200, reminder suppression, and a `next_todo_id` on completion. Deletion was empty 204, soft-deleted edit 404 and restore 200 kept the same UUID. Foreign to-do edit 403, foreign restore 404, malformed UUID 404. | `TodoTest`, `TodoSubtaskTest`, `RepeatTodoTest`, `TodoRestoreTest` and `ReminderTest` cover validation, retention and repeat/reminder edge cases. `RepeatTodoTest` verifies that undo and re-completion retain the same next ID and create no duplicate. |
| Schedule, intention and streak | **PASS**. Item creation 201. Week and day 200 with seven days and the expected occurrence. Now/next returned 200. The top-level `now` matched the fixture at 09:00 and was null at the exclusive end time. Invalid day and out-of-window date 422. Override 200; first check-in 201 and repeat 200, foreign check-in mutation 403. Intention read/write 200 with tenant-specific values, streak read 200. | `ScheduleItemTest`, `ScheduleOccurrenceTest`, `DailyIntentionTest`, `StreakTest` and `LocalDateTest` cover overlaps within a day, local midnight, time zones, completion, skipped occurrences and invalid dates. `ScheduleOccurrenceTest` asserts both top-level now/next fields. |
| Guest import | **PASS**. Initial `POST /import` 200 inserted rows. A later chunk added an exception to the existing item. Replaying both chunks returned 200 with skipped counts. A foreign UUID and a 501-to-do payload returned 422, with no oversized fixture rows persisted. | `ImportTest` covers per-request rollback, duplicate IDs/days, conflicting server-edited rows, existing totals and exception/check-in payload limits. Browser interruption, session change and guest data retention remain QA-009. |
| Feedback | **PASS**. Guest and bearer submissions returned 201, persisted expected ownership and produced local forward mail. A 5,001-character message returned 422. The eleventh request in an independent throttle phase returned 429 after ten accepted submissions. Account deletion removed linked feedback and kept guest feedback. | `FeedbackAndAccountTest` verifies persistence when mail fails and when no forwarding recipient is configured. |
| Throttling and errors | **PASS**. Eleventh auth attempt returned 429 after ten invalid 422 logins. Both auth and feedback 429s returned JSON and integer `Retry-After`. After 120 authenticated reads in one window, logout and deletion returned 429 with integer `Retry-After` exposed to the configured origin. During a database network interruption, shallow health was 200, deep health 503 with `database: unavailable`, and bearer `GET /me` returned exactly `{"message":"Server Error"}` with debug off. Reconnection restored deep health 200 on the same 16 migrations and the same witness row. | `HardeningTest` and `AuthTest` cover production-style JSON errors and rate limits. Transaction faults are exercised in Pest. No concurrent planner write-conflict rule is specified by the contract. |

## OpenAPI parity

A recursive comparison of the parsed OpenAPI documents found the same 32 paths and 42 HTTP operations. Request schemas and fields, nullable and optional properties, auth rules, shared error and resource schemas, and the response envelopes agree. Three response entries and nine descriptions differ.

| JSON Pointer or operation | Backend contract delta |
|---|---|
| `/info/description` | Adds canonical-origin CORS without credentials, invalid-origin startup/runtime behavior, security headers on preflights and HSTS on HTTPS. |
| `GET /auth/google/callback` description | Clarifies `google_failed` when the email points to a different already-linked Google subject. Redirect and error values remain shared. |
| `POST /auth/google/exchange` description and 422 description | A newer callback, successful password reset or deletion invalidates the five-minute grant. The same 422 on `code` also describes replaced or revoked grants. |
| `POST /auth/logout` responses | Adds 429 with `#/components/responses/TooManyRequests`, including integer `Retry-After` and generic error body. |
| `POST /auth/password/forgot` description | The 60-minute link is associated with the account at issuance. A generic acknowledgement also covers mail transport failure. |
| `POST /auth/password/reset` description | Clarifies that all bearer tokens and the outstanding Google exchange grant are invalidated. |
| `GET /me` description | A read authenticated before revocation may still finish. |
| `DELETE /me` description and 422 description | Bearer and current password are required. User, associated rows and credentials are deleted transactionally. A Google-only account without a password gets 422 under `password`. The stale frontend wording implies a future Google re-auth policy. |
| `DELETE /me` responses | Adds 429 with the shared throttle response and 500 with `#/components/schemas/Error` for deletion failure with transaction rollback. |

The planner should synchronize the frontend from backend revision `1188595d4aa0b3c5ab2638b4ee6db0d2829640e7:docs/openapi.yaml` or a verified successor. The fetched frontend auth client uses the same bearer and token response shapes, checks account deletion for exactly 204, and already parses generic API errors and `Retry-After`. It separately guards Google-only deletion UI with `has_password`. The frontend sends bounded import chunks in dependency order and retains guest data until all chunks succeed. Backend transactions apply per request. The older data-model rule describes one import call, so clarify the intended end-to-end guarantee before editing that rule. [Data model](08-data-model.md) · [Backend contract](openapi.yaml)

## Security review

- The runtime accepts one configured frontend origin, with forwarded HTTPS determining HSTS. `bootstrap/app.php` trusts forwarded headers from all proxies, so Q25 must verify that the deployed edge is the exclusive ingress and supplies trustworthy HTTPS and client IP forwarding. The local header simulation establishes application behavior. It does not establish the hosted ingress, TLS or source IP boundary.
- Account mutations that recheck Sanctum under the shared user lock cover profile, survey, resend and deletion. Reads admitted at request entry may finish after revocation. The B-037 scope decision still applies to a Google OAuth flow that has not resolved an account. Provider entry, browser state, grant secrecy, deployed log scrubbing and Google consent require paired staging evidence. [B-037 progress](PROGRESS.md#2026-11-21-b-037-credential-lifecycle-across-authentication-boundaries)
- Auth credentials and account cleanup use the existing PostgreSQL lifecycle and transactional behavior. A single candidate serving credential traffic and an owner-approved rollback plan remain deployment gates. This disposable run started on the current schema. Upgrade evidence is a separate B-038 rehearsal.
- Both Composer locked audits reported zero advisories when run. The API's production error response and health outage envelope were observed directly. Provider, edge and platform logging are separate controls that need owner evidence.

## Commands and quality gates

Commands ran on the verified backend archive and read-only frontend ref.

| Command or check | Observed result |
|---|---|
| `git fetch origin v2-rebuild` in each repository, `git show origin/v2-rebuild:docs/openapi.yaml` and PyYAML 6.0.3 recursive comparison | Exact SHAs and parity delta above. |
| `git archive --format=tar HEAD` piped to `docker build --quiet -t loomora-b039:1188595d4aa0 -` | Built successfully as `sha256:82fbb94875d12c47e61f446f9247ac4aa94e4cb0a74541616f3fdf61a2d00741`. |
| `docker exec loomora-b039-api php artisan migrate:status --no-ansi` and cache-file checks | 16 migrations ran, config and route cache files present. |
| `docker exec loomora-b039-api php /tmp/b039-http.php` with `main`, `recovery`, `google`, `throttle`, `signed-throttle`, `outage-seed`, `outage`, `recovered` | Status and state assertions in the local matrix passed. The outage used `docker network disconnect` and `connect` on the dedicated database without restarting it. A local cache reset separated unrelated rate limit phases. |
| `composer install --no-interaction --prefer-dist --no-progress` | Installed all 141 locked dependencies on PHP 8.3.37 in the archived exact source tree using a read-only dist cache. The internal network precluded an install-time Packagist filter lookup. Both explicit locked audits ran separately online. |
| `php vendor/bin/pest ... --colors=never` with AuthTest, EmailAndPasswordTest, GoogleAuthTest, GoogleAccountRecoveryTest, PasswordResetAtomicityTest, FeedbackAndAccountTest, HardeningTest, FrontendOriginTest and ImportTest on PostgreSQL | 153 passed, 0 skipped, 0 failed, 957 assertions, 110.53 s, exit 0. |
| `php vendor/bin/pest tests/Feature/CredentialConcurrencyTest.php --colors=never` on PostgreSQL | 24 passed, 0 skipped, 0 failed, 179 assertions, 39.21 s, exit 0. |
| `php vendor/bin/pest --colors=never` on PostgreSQL | 448 passed, 0 skipped, 0 failed, 2,143 assertions, 151.67 s, exit 0. |
| `php vendor/bin/pest --colors=never` on in-memory SQLite | 424 passed, 24 PostgreSQL cases skipped, 0 failed, 1,964 assertions, 129.27 s, exit 0. |
| `php vendor/bin/pint --test --no-ansi`, `php vendor/bin/phpstan analyse --no-progress --memory-limit=1G`, B-017 unused-code review | Pint passed 278 files, Larastan reported no errors, and the patch adds no code or dependencies. |
| `composer validate --strict`, `composer audit --locked`, `composer audit --locked --no-dev` | Exit 0 for each. Both audits reported no security advisories. |
| `npx --yes @redocly/cli lint docs/openapi.yaml` | Exit 0 with the existing localhost example, health 4xx and two redirect 2xx warnings. |

No product regression required RED/GREEN. The first focused invocation lacked the CI-style generated `.env` and application key, which caused the reset broker's missing-key errors. Copying the repository example inside the disposable dev container and generating a synthetic application key restored the documented setup. The first HTTP probe also needed corrections to its mail parser and to expectations that differed from the existing contract (foreign to-do edit 403 and top-level schedule now/next). These were local probe corrections.

## Hosted staging owner checklist

| Item | Status | Redacted evidence to record | Owner |
|---|---|---|---|
| Frontend deployed SHA and routes | **BLOCKED** | Approved staging URL, exact deployed Git SHA, direct app and auth landing routes. | Frontend deployment owner |
| Backend deployed SHA | **BLOCKED** | Exact backend candidate SHA, deployed revision, startup and migration completion. | Render owner |
| Separate disposable staging PostgreSQL | **BLOCKED** | DB owner confirmation of isolated data and synthetic accounts. | Neon owner |
| Render app and frontend origin | **BLOCKED** | Canonical HTTPS URL and exact Origin match, debug disabled, environment-specific app key and proxy-only ingress. Record forwarded HTTPS/client-IP observations without values that identify private users. | Render owner |
| Neon direct TLS connection | **BLOCKED** | Direct connection and TLS-required mode verified. Record pass/fail without the connection string. | Neon owner |
| Google OAuth | **BLOCKED** | Configured staging client, approved exact callback and browser consent with a synthetic account. Verify both success and failure redirects. | Google and QA-009 owners |
| Brevo sender | **BLOCKED** | Staging sender verified, environment-specific transport configuration confirmed and approved test recipient selected. | Brevo owner |
| Verification email delivery | **BLOCKED** | Receipt, canonical landing route, signed redemption and expiry on the staging pair. | QA-009 owner |
| Reset email delivery | **BLOCKED** | Receipt, canonical landing route, redemption, old credentials rejected and fresh sign-in. | QA-009 owner |
| Signup/sign-in, logout and revocation | **BLOCKED** | Browser signup/sign-in and logout, reset from one session and ensure the other session is revoked. | QA-009 owner |
| Google-only recovery and explicit deletion | **BLOCKED** | Q23 approval or explicit launch scope, then paired browser proof of verified recovery, preserved account, fresh sign-in, explicit deletion and old bearer rejection. | Planner and QA-009 owner |
| CORS, CSP and ingress | **BLOCKED** | Exact frontend origin, allowed preflight, unrelated-origin denial, HTTPS headers and browser CSP with both deployed candidates. | Render and frontend owners |
| 375 px and guest import | **BLOCKED** | Browser startup and the complete synthetic workflow at 375 px, including interrupted import, safe retry and guest retention. | QA-009 owner |
| Migration and rollback ownership | **BLOCKED** | Named migration and restore owners, disposable restore rehearsal evidence, approved cutover order, fencing and rollback criteria. | Render, Neon and planner owners |

Keep **Q23**, **Q25** and **QA-009** open until the planner and owners record their decisions and staged evidence. [Open questions](PROGRESS.md#open-questions) · [Deployment guide](DEPLOY.md)
