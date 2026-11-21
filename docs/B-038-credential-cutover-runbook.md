# Credential cutover from B-036 to B-037

**Release condition.** Only one backend revision may handle credentials and protected account changes at a time. Schedule a maintenance window before promoting this candidate. The rehearsal used a disposable PostgreSQL 16.16 database and two production Docker images. Hosted configuration, backup restoration and paired staging still require owner evidence.

| Revision | Verified Git commit | Database state |
|---|---|---|
| B-036 baseline | `24d4e7225fe212dfe73e41be9827e8509fe625ac` | 15 migrations. Google exchange codes live in cache for five minutes. |
| B-037 candidate | `1188595d4aa0b3c5ab2638b4ee6db0d2829640e7` | 16 migrations. The latest Google exchange digest and its expiry live on each user row. |

## Reproduce the populated-database upgrade

Use a local Docker Engine, Git with both commit objects and Bash. From the repository root run:

```bash
bash tests/Support/cutover/rehearse.sh --disposable-postgres
```

The script builds both revisions from Git, creates its own internal Docker network and a fresh RAM-backed PostgreSQL database, and publishes no ports. It seeds the old release through its models, broker, Sanctum and actual callback with synthetic Google responses. It then stops the old app, starts the candidate against the same database, compares the original columns before the first authenticated HTTP request, and tests credentials through Apache. Finally it stops the disposable database and checks the production HTTP 500 response. The shell removes its own containers and network on exit. It uses an ephemeral application key and local mail transport. Build dependencies may require registry access. Only the status lines and counts belong in the release record. [Rehearsal script](../tests/Support/cutover/rehearse.sh) · [Fixture and assertions](../tests/Support/cutover/fixture.php)

| What survived the migration | Observed evidence |
|---|---|
| Users and verification | Three original UUIDs. One unverified password account, one verified Google-linked password account and one verified Google-only account. Every original user field matched, including the two password hashes, the null password, subjects, preferences and verification timestamps. Both grant columns were null for all three accounts. |
| Sessions and recovery | All three Sanctum rows and the single broker row matched before use. Each original bearer returned HTTP 200 and its original UUID from `GET /me`. |
| Planner history | Two to-dos including one soft-deleted row, one schedule item, one exception, one check-in and one daily intention. Counts, IDs, foreign keys, content and timestamps matched across the upgrade. |
| Migration | The candidate applied the additional users-table migration. It adds two nullable columns and a unique index on the digest. Original-row comparisons cover all eight populated application/credential tables. This fixture cannot predict production index-build time or lock contention. |

## Credential compatibility matrix

“Upgrade” denotes the two-image rehearsal above. “Pest” denotes the candidate's independent local regression suite with synthetic users, database credentials and mocked providers. Results refer to the same-account contract except where a new UUID is specified.

| Case | Observation | Evidence |
|---|---|---|
| B-036 cached Google grant | Still present and live in the shared database cache when B-037 returned 422 on `code`. Restart Google sign-in. | Upgrade |
| Fresh B-037 Google grant | Fresh provider callback produced a grant. Its exchange returned 200, a bearer, expiry and the original linked UUID. | Upgrade |
| Duplicate exchange | Second use returned 422. Concurrent exchanges admitted one winner. | Upgrade and PostgreSQL Pest |
| Reset with pending B-037 grant | A reset cleared the pending grant. Exchange returned 422. A later fresh callback could sign in the account again. | Upgrade and Pest |
| Reset with active sessions | The original bearer and a bearer just issued by B-037 both returned 401 after successful reset. | Upgrade |
| Account deletion with credentials | Password-confirmed deletion returned 204. The deleted bearer, unconsumed grant and pending recovery link were rejected. | Pest |
| Same email registered after deletion | Registration returned 201, explicit deletion returned empty 204, and re-registration returned 201 with a new UUID. The old bearer returned 401. The old recovery link could not reset the replacement. | Upgrade and Pest |
| Reset link issued by B-036 | An unexpired link for a fixture with proven ownership redeemed under B-037. Migration preserves the email-keyed broker row and its original expiry. | Upgrade |
| Link issued by B-037 | New issuance and redemption succeeded. The configured 60-second reissue throttle and 60-minute expiry remained in effect. | Pest and broker config |
| Unknown, expired or superseded credentials | Invalid, expired and superseded Google codes and stale recovery tokens received 422. Invalid or revoked Sanctum bearers received 401. | Pest |

[Google exchange regressions](../tests/Feature/GoogleAuthTest.php) · [Password and email tests](../tests/Feature/EmailAndPasswordTest.php) · [Reset atomicity](../tests/Feature/PasswordResetAtomicityTest.php) · [Recovery and explicit deletion](../tests/Feature/GoogleAccountRecoveryTest.php) · [PostgreSQL ordering tests](../tests/Feature/CredentialConcurrencyTest.php)

## Why mixed versions cannot serve authentication

The B-036 callback writes a cache entry. The B-037 callback stores and supersedes a grant on the locked user row. Each exchange handler reads only its own store. Load balancing can send the callback and exchange to different versions, causing a fresh sign-in to fail. More importantly, a B-037 reset clears the database grant and current bearers, while the legacy handler can still authorize a cached grant for a surviving account. A reset handled by B-036 also leaves a pending B-037 database grant outside its revocation work. Old account-management paths lack B-037's shared transaction guarantees. These mixed-writer conclusions come from comparing the two releases' handlers. The rehearsal ran them sequentially. [B-036 Google handler](https://github.com/danishsary8/loomora_back/blob/24d4e7225fe212dfe73e41be9827e8509fe625ac/app/Http/Controllers/Api/V1/GoogleAuthController.php) · [B-037 Google handler](../app/Http/Controllers/Api/V1/GoogleAuthController.php) · [B-037 reset handler](../app/Http/Controllers/Api/V1/PasswordResetController.php)

B-037 permits an ordinary read or resource action already authorized at request entry to finish. Protected account mutations recheck the bearer. An outstanding Google flow that has yet to resolve a Loomora identity is evaluated against the account current at initial identity lookup. The planner owns any stronger guarantee. [B-037 credential policy](PROGRESS.md#2026-11-21-b-037-credential-lifecycle-across-authentication-boundaries)

## Owner cutover procedure

1. **Record the target and recovery point.** Record the approved candidate SHA, environment, direct TLS database identity (redacted), existing migration ledger, scope/counts of the affected tables, responsible operators and change window. Complete the approved paired staging run, including real provider consent, delivery and the synthetic credential matrix, before scheduling the production cutover. Keep the environment-specific app key and existing broker and cache configuration stable. Confirm the backup/PITR retention covers cutover and record a successful restore to a separate isolated target, with migration ledger and row counts verified. Record a timestamp and pass/fail only. A backup listed in a dashboard alone is insufficient.
2. **Close ingress and drain writers.** Gate the whole API at an owner-controlled point upstream of every old and new instance, including alternate hostnames and direct routes. Refuse new callbacks, registration, login, recovery and account mutations, and stop new credential jobs from being admitted. Let already-admitted requests and jobs finish on their original revision. Record the final completion time, then verify zero old requests or workers and no reachable old instance. If this gate or drain cannot be demonstrated, postpone. Render can keep the old instance accepting traffic while its replacement starts and shuts it down after switching routing. The entrypoint runs migrations before Apache. A healthy replacement alone cannot prove this drain. [Render deployment sequence](https://render.com/docs/deploys#zero-downtime-deploys) · [Image entrypoint](../docker/entrypoint.sh)
3. **Account for outstanding credentials.** B-037 immediately rejects even a live legacy Google code. For a conservative cutover, keep legacy handlers fenced for at least five full minutes after the last possible old grant issuance, plus clock uncertainty, so such a grant cannot survive accidental re-entry during the switch. Waiting alone cannot make mixed versions safe. For broker rows, choose the evidence-based path before reopening redemption. If owner records establish continuous ownership of every affected email since issuance, documented unexpired links remain usable. If history is unavailable or ambiguous, keep reset issuance and redemption fenced through the full configured expiry (60 minutes here, plus clock margin) from the last possible pre-cutover issuance. Confirm the remaining legacy rows have expired. Users can then request new links. Physical presence of an expired broker row does not confer validity. An email and issuance timestamp alone cannot identify a historical owner. Keep all fences in place if the timings cannot be established. [Broker configuration](../config/auth.php)
4. **Start exactly one candidate while fenced.** Verify the image SHA. Let the entrypoint cache configuration and routes and run `php artisan migrate --force --no-interaction` once before additional instances start. Monitor blocking transactions and the unique index build within the approved window. The users-table DDL can require an exclusive lock. Compare the migration ledger, both nullable columns and the unique index with the committed migration. Compare pre/post row counts under the traffic fence. An unexpected ledger, prolonged lock wait or partial schema keeps traffic closed. [Migration](../database/migrations/2026_11_21_000000_add_google_exchange_grant_to_users_table.php) · [PostgreSQL 16 lock behavior](https://www.postgresql.org/docs/16/sql-altertable.html)
5. **Verify and reopen.** Check shallow and deep health from the owner control path. Both must return 200 and deep health must report database `ok`. Confirm every serving instance and any workers run the intended SHA, including after automatic rollback or restart. For a staging cutover, run the matrix with synthetic accounts and approved provider recipients, and verify the inbound HTTPS origin and delivered links. For a production cutover, use the already-approved staging evidence and read-only public checks. Retain redacted outcomes only. Reopen gated traffic only after the aging windows, identity and migration evidence and owner gates pass. Monitor 401/422 and 5xx rates, failed callbacks and database locks against pre-cutover levels.

## Recovery decision tree

| Condition | Operator action |
|---|---|
| Preparation fails before the migration or any candidate credential writes | Abort the promotion. Verify only the original SHA and its original state are active, document the reason and have the owner authorize restoring normal B-036 service under its prior guarantees. |
| Startup or migration fails, or schema status is uncertain | Keep the fence. Inspect sanitized migration errors and read-only ledger/index metadata on the expected database. Resolve the connection, lock or candidate issue and retry the verified candidate once the exact schema is understood. Escalate a ledger/schema mismatch to the DB owner. Preserve all data and the additive columns. |
| Migration is complete but the candidate fails smoke checks | Keep the fence. Correct configuration, restart the same verified candidate or deploy a reviewed fix retaining B-037 credential semantics. Re-run health, ledger, counts and staging smoke. An already applied migration stays in place. |
| Candidate has handled writes, routing overlapped, or the deployed revision is uncertain | Close ingress, stop all legacy writers, record affected time range and revisions, and preserve redacted evidence. Treat uncertain credential issuance as an incident. The security owner determines affected sessions, necessary reauthentication and communication. Reopen only on a verified B-037-compatible version after the incident gate. |
| Pressure to select a pre-B-037 Render rollback | Keep the credential fence and seek planner/security approval. Waiting out old codes does not give a newly restarted old handler the candidate's revocation guarantees for future requests. Recover service with a known-good revision that retains B-037 semantics. |
| Database corruption or severe data loss is suspected | Isolate writes and restore the recovery point into a separate database for validation and reconciliation. Escalate any replacement of live data to its owner. A historical restore can resurrect revoked credentials and deleted accounts, or discard later writes. An application rollback does not require a schema downgrade. |

**Outstanding approvals.** Danish or the planner owns the maintenance mechanism, restore proof, reset-history decision, incident handling and promotion. Q23 deletion assurance, Q25 hosted configuration and QA-009 paired staging/provider evidence remain open. This rehearsal authorizes no production action.
