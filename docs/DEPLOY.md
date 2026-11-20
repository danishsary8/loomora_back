# Deploying the Loomora API

This guide puts the backend online for the first time. Follow the parts in order. It takes about 45 minutes. You do not need any DevOps experience; every click is written down.

You will set up four free services:

| Service | What it does for Loomora |
|---|---|
| **Neon** | The PostgreSQL database |
| **Brevo** | Sends emails (verify email, reset password, feedback) |
| **Google Cloud** | "Sign in with Google" |
| **Render** | Runs the API (the Docker image from this repo) |

Keep a private note open (a password manager is best) to collect the values below. **Never paste them into git, an issue, or a chat.**

| Value | Where it comes from | Goes into Render as |
|---|---|---|
| Neon direct connection string | Part 1 | `DB_URL` |
| Brevo API key | Part 2 | `BREVO_API_KEY` |
| Verified sender email | Part 2 | `MAIL_FROM_ADDRESS` |
| Google client ID and secret | Part 3 | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` |
| App key | Part 4 | `APP_KEY` |

---

## Part 1: Neon (database)

1. Go to <https://console.neon.tech> and sign in.
2. Click **New Project**.
   - **Name:** `loomora`
   - **Postgres version:** 16
   - **Region:** a **US** region (for example *AWS US East (Ohio)*). Render's free servers are in the US, so this keeps the database close.
3. Click **Create project**.
4. On the project dashboard, click **Connect**.
5. In the dialog:
   - Turn **Connection pooling OFF**. Loomora needs the **direct** connection, because migrations do not work through the pooler.
   - Copy the connection string. It looks like this (yours has real values):
     `postgresql://neondb_owner:********@ep-example-123456.us-east-2.aws.neon.tech/neondb?sslmode=require`
   - Check that the host does **not** contain `-pooler`. If it does, pooling is still on.
6. Save it in your note as **DB_URL**.

> If you used Neon for the old Daycraft app: decision S3 says the database is "wiped fresh". Use a **new** project as above rather than the old one.

## Part 2: Brevo (email)

1. Go to <https://app.brevo.com> and create a free account (or sign in).
2. **Verify a sender** (the "From" address of Loomora emails):
   - Top-right menu → **Senders, Domains & Dedicated IPs** → **Senders** → **Add a sender**.
   - Name: `Loomora`. Email: an address you can open (for example your Gmail).
   - Open the confirmation email Brevo sends and click the link.
   - Save this address as **MAIL_FROM_ADDRESS**.
3. **Create an API key**:
   - Top-right menu → **SMTP & API** → tab **API Keys** → **Generate a new API key**.
   - Name: `loomora-api`. Copy the key now; Brevo shows it only once.
   - Save it as **BREVO_API_KEY**.
4. Decide where feedback should arrive (probably your own email). Save it as **FEEDBACK_TO_EMAIL**.

## Part 3: Google sign-in

Decision S7: keep the **same Google client ID** as before, but create a **new secret** and new redirect URIs.

You need the API's address for this. Render gives it in Part 4 (step 4); it looks like `https://loomora-api.onrender.com`. If you do not know it yet, do Part 4 up to "Create Web Service" first, then come back.

1. Go to <https://console.cloud.google.com> → choose your project.
2. Menu → **APIs & Services** → **Credentials**.
3. Under **OAuth 2.0 Client IDs**, click your existing web client.
4. **Authorized JavaScript origins:** use the canonical frontend origin for this API environment. For production, use `https://loomora-craft.vercel.app`.
5. **Authorized redirect URIs:** add exactly:
   `https://<api-host>/api/v1/auth/google/callback`
   for example `https://loomora-api.onrender.com/api/v1/auth/google/callback`.
   Remove the old Daycraft redirect URIs.
6. Click **Save**.
7. Still on that page, under **Client secrets**, click **Add secret**. Copy the new secret, then **disable and delete the old secret** (the old one may have been exposed, see `docs/SECURITY-FINDINGS.md`).
8. Save **GOOGLE_CLIENT_ID** (shown at the top of the page) and **GOOGLE_CLIENT_SECRET**.
9. **GOOGLE_REDIRECT_URI** is the same URL you added in step 5.

## Part 4: Render (the API)

### Create the service

1. Go to <https://dashboard.render.com> and sign in with GitHub.
2. Click **New +** → **Blueprint**.
3. Connect the repository `danishsary8/loomora_back`, branch `main` (or `v2-rebuild` until it is merged into `main`).
4. Render reads `render.yaml` and shows a service called **loomora-api** (Docker, Free plan, health check `/api/v1/health`). Click **Apply**.
   - Your API address appears at the top of the service page, e.g. `https://loomora-api.onrender.com`. This is **APP_URL**.
5. The first deploy will **fail**, because the environment variables are still empty. That is expected.

### Make the app key

`APP_KEY` must look like `base64:` followed by 44 characters. Make one with **either**:

- PHP on your computer, in this repo: `php artisan key:generate --show`
- or Docker: `docker run --rm php:8.3-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`

Copy the whole line, including `base64:`, as **APP_KEY**. Generate it once and keep it: changing it later logs everyone out and breaks links in emails already sent.

### Fill in the environment variables

On the **loomora-api** service → **Environment** → for each name below, click **Edit** and paste the value:

| Key | Value |
|---|---|
| `APP_KEY` | from "Make the app key" |
| `APP_URL` | your Render address, e.g. `https://loomora-api.onrender.com` |
| `FRONTEND_URL` | One canonical frontend origin, with no path or trailing `/`. Production uses `https://loomora-craft.vercel.app`. |
| `DB_CONNECTION` | `pgsql` |
| `DB_URL` | Neon direct connection string (Part 1) |
| `GOOGLE_CLIENT_ID` | Part 3 |
| `GOOGLE_CLIENT_SECRET` | Part 3 |
| `GOOGLE_REDIRECT_URI` | `https://<api-host>/api/v1/auth/google/callback` |
| `MAIL_MAILER` | `brevo` |
| `BREVO_API_KEY` | Part 2 |
| `MAIL_FROM_ADDRESS` | Part 2 (the verified sender) |
| `FEEDBACK_TO_EMAIL` | Part 2 |

Set `APP_DEBUG=false` for production and hosted staging. Laravel also defaults to `false` when the setting is absent.

Set `FRONTEND_URL` to the frontend's exact browser origin. Use a lowercase scheme and host and include a port only when it differs from the scheme's default. Comma-separated lists, whitespace, credentials, paths (including a trailing `/`), query strings and fragments fail validation. Always set the approved HTTPS origin explicitly on Render. Local HTTP is supported on `localhost`, `127.0.0.1` and `[::1]`. An unset value defaults to `http://localhost:5173` for development and build tooling. An explicitly empty value fails validation.

The API uses this origin for CORS, the Google callback to the frontend, password reset and email verification. Use a separate approved staging frontend, API and database for preview authentication. The Render owner confirms the actual configured origin as part of [Q25](PROGRESS.md#q25-owner-verification-checklist).

Click **Save Changes**, then **Manual Deploy** → **Deploy latest commit**.

## Part 5: First-deploy checks

Run registration, mail and Google checks only on an approved, disposable staging pair with synthetic accounts and approved test recipients. On a shared public deployment, limit this assessment to read-only health, response headers, CORS and direct frontend route checks. Record the exact frontend and backend commits and attach the redacted provider evidence in the [release checklist](PROGRESS.md#q25-owner-verification-checklist).

1. **Logs:** service → **Logs**. You should see the migrations, then Apache starting. If you see `FATAL: migrations failed after 3 attempts`, the database is unreachable: check `DB_URL` (direct, not `-pooler`, ends with `?sslmode=require`).
   If the logs report `FRONTEND_URL must be one canonical frontend origin.`, correct the value before redeploying. Docker validates it during `php artisan config:cache`, before migrations and Apache startup. Restart or redeploy after changing the origin so Laravel rebuilds the cache.
   For a local upgrade, an invalid value in an old cache also blocks Artisan commands. Correct `.env` and remove the generated `bootstrap/cache/config.php` file (or the generated file at `APP_CONFIG_CACHE` if set), then rerun the command.
2. **Health:** open `https://<api-host>/api/v1/health` → `{"status":"ok"}`.
3. **Database:** open `https://<api-host>/api/v1/health?deep=1` → `{"status":"ok","database":"ok"}`.
4. **Staging registration and email:** use the approved staging API and test recipient.
   ```bash
   curl -X POST https://<approved-staging-api-host>/api/v1/auth/register \
     -H "Content-Type: application/json" -H "Accept: application/json" \
     -d '{"name":"Test","email":"APPROVED_TEST_EMAIL","password":"a-long-test-password","timezone":"Asia/Phnom_Penh"}'
   ```
   You get a JSON reply with a `token`, and a "Confirm your email" message arrives (check spam). If no email arrives, check `BREVO_API_KEY` and that `MAIL_FROM_ADDRESS` is verified in Brevo.
5. **Staging Google sign-in:** use an approved test account on the paired staging frontend. A `redirect_uri_mismatch` error from Google means the redirect URI in Part 3 does not exactly match `GOOGLE_REDIRECT_URI`.
6. **CORS:** the web app should load data without "CORS" errors in the browser console. If it shows them, check that `FRONTEND_URL` exactly matches the address in the browser bar (https, no trailing `/`).

## Good to know

For an existing deployment crossing from B-036 to B-037, follow the [credential cutover and rollback runbook](B-038-credential-cutover-runbook.md) before a push or manual deploy reaches the service. The two versions require a single-version credential traffic cutover.

- **Free plan sleep:** Render's free service sleeps after 15 minutes without traffic. The next request takes up to about a minute while it wakes up. Neon also sleeps; the app retries migrations three times to cover this.
- **Every push to the deployed branch redeploys** automatically, and migrations run on start.
- **Changing a secret:** edit it in Render → **Environment** → **Save Changes**. Render restarts the service.
- **Never** put real values in `.env.example`, `render.yaml`, or any file in git.
