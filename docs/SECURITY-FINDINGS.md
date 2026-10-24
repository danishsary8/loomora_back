# Security Findings: secret scan of loomora_back

Date: 2026-10-24
Branch scanned: `v2-rebuild` (same commit as `main`, `f484301`), plus all refs in history.
Rule: no real secret values are written here. Only the file path, the key name, and the first 4 characters.

## 1. How the scan was done

| Step | Tool | Scope | Result |
|---|---|---|---|
| 1 | `gitleaks git --redact` (v8.21.2, default rules) | All 11 commits on all branches | No leaks found |
| 2 | `gitleaks dir --redact` | Working tree (after the move to `legacy/`) | No leaks found |
| 3 | `git log -p --all` + pattern search | Every added line in history | See section 2 |
| 4 | Pattern search | Every tracked file in the working tree | See section 2 |
| 5 | `git log --diff-filter=A --name-only` | Files ever added with sensitive names (`.env`, `*.key`, `*.pem`, credentials, `auth.json`) | Only `.env.example` and `.env.production.example` |

Patterns searched: `client_secret`, `GOCSPX`, `password`, `passwd`, `APP_KEY`, `DB_URL`, `DATABASE_URL`, `api_key` / `api-key`, `secret`, `token`, `AKIA`, `sk_live`, `xkeysib` (Brevo), `BREVO`, `MAIL_PASSWORD`, `base64:` (Laravel app key).

## 2. Findings

### 2.1 Exposed secrets

**None found.** No real credential, API key, Google client secret (`GOCSPX...`), Laravel `APP_KEY` (`base64:...`), database URL, or mail password appears in the working tree or in any commit.

### 2.2 Placeholders and empty values (safe, listed for completeness)

| File | Key | First 4 chars | Note |
|---|---|---|---|
| `legacy/.env.example` | `APP_KEY` | (empty) | Safe |
| `legacy/.env.example` | `DB_PASSWORD` | `your` | Placeholder text |
| `legacy/.env.example` | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | (empty) | Safe |
| `legacy/.env.example` | `RESEND_API_KEY`, `DIAGNOSTIC_TOKEN`, `SENTRY_LARAVEL_DSN` | (empty) | Safe |
| `legacy/.env.production.example` | `APP_KEY`, `DB_URL` | (empty) | Safe |
| `legacy/.env.production.example` | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | (empty) | Safe |
| `legacy/.env.production.example` | `RESEND_API_KEY`, `DIAGNOSTIC_TOKEN`, `SENTRY_LARAVEL_DSN` | (empty) | Safe |
| `legacy/.env.production.example` | `ANALYTICS_ADMIN_EMAILS` | `owne` | Placeholder-style address, not a secret |

### 2.3 Test fixtures (safe)

Hard-coded passwords and tokens appear only in automated tests and the user factory. They are fake values used to test the app, not real credentials.

| File | Key | First 4 chars |
|---|---|---|
| `legacy/tests/Feature/AuthTest.php` | `password` | `Str0`, `shor`, `tota` |
| `legacy/tests/Feature/AccountDeletionTest.php` | `password` | `Str0`, `irre`, `not-` |
| `legacy/tests/Feature/AccountEssentialsTest.php` | `password` | `Stro`, `OldS`, `NewS` |
| `legacy/tests/Feature/AnalyticsTest.php` | `password` | `Stro` |
| `legacy/tests/Feature/MailDiagnosticTest.php` | `token` | `wron`, `corr` |
| `legacy/tests/Unit/MonitoringPrivacyTest.php` | `token`, `password` | `secr`, `Secr` |
| `legacy/database/factories/UserFactory.php` | `password` | `stat` (uses a static hashed value) |

### 2.4 Related risks (not secrets, but worth fixing)

| # | File | Risk | Suggested action |
|---|---|---|---|
| R1 | `legacy/routes/api.php` (lines 35-42), `legacy/app/Http/Controllers/Api/V1/MailDiagnosticController.php` | Temporary mail-test endpoints (`internal/mail-diagnostic`, `internal/mail-test`) are marked "TEMPORARY - DELETE AFTER USE" but are still in the code. If deployed, anyone holding the token can trigger emails. | Do not carry these into the new code (matches decision S16). Turn off any running deployment that still serves them. |
| R2 | Same controller | The diagnostic token is sent as a URL query parameter (`?token=`). URLs are often stored in proxy and host logs. | If a `DIAGNOSTIC_TOKEN` is set on Render, remove or rotate it. |
| R3 | Host dashboards (Render, Google Cloud, Resend) | Real secrets live outside git. The scan cannot see them. | Rotate the Google client secret as already planned (S7, S16) and check the old Render service's environment variables. |
| R4 | `.gitignore` | Root-anchored patterns such as `/vendor` and `/storage/*.key` no longer match the copies under `legacy/`. `.env` (unanchored) is still covered. | When new code is scaffolded, review `.gitignore` so `legacy/vendor` and similar paths stay ignored. |

## 3. Summary

- Exposed secrets: **0**.
- The repo is public. History is clean, so no history rewrite is needed.
- Follow-ups: R1 to R4 above. R3 needs the owner (secrets and host dashboards).
