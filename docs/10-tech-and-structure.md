# Loomora: Tech Decisions and Repo Structure (A8, v1.0)

## Stack
**Frontend** (`Loomora_front`, hosted on Vercel): React 18, Vite, TypeScript (strict), Tailwind CSS, React Router, TanStack Query (server data), Zustand (small UI state), Dexie (IndexedDB for guest data), vite-plugin-pwa, i18next (English now, Khmer later), Framer Motion (stamp and sheet animations), date-fns, Vitest + Testing Library (unit), Playwright (end-to-end), ESLint + Prettier.

**Backend** (`loomora_back`, hosted on Render, Docker): Laravel 12, PHP 8.3, PostgreSQL on Neon (direct, non-pooled connection), Sanctum, Socialite (Google), Pest (tests), Laravel Pint (style), Brevo (email via API or SMTP), Sentry optional later.

**CI** (GitHub Actions, both repos): lint, type check, tests on every PR. Merging needs green checks.

## Decisions (ADR short form)
| # | Decision | Why |
|---|---|---|
| A8-1 | Bearer tokens, not cookies | The frontend and API sit on different domains on free hosting |
| A8-2 | UUID primary keys | Guest data keeps ids when imported |
| A8-3 | Computed Overdue and streak, no cron | Free tier has no reliable scheduler |
| A8-4 | Guest data in IndexedDB (Dexie) | More space than localStorage and structured |
| A8-5 | Same data shapes for guest and server | One set of types and one set of UI code; a storage adapter hides where data lives |
| A8-6 | OpenAPI file in docs | Clear contract for both repos |
| A8-7 | Fixed 6 categories as a string enum | Simple, consistent colors |
| A8-8 | Client sends local date and time zone | Correct "today" for every user |

## Backend structure (loomora_back)
```
app/Http/Controllers/Api/V1/   one controller per area
app/Http/Requests/             validation
app/Http/Resources/            JSON shapes
app/Models/                    User, Todo, ScheduleItem, ScheduleException, ScheduleCheckin, Feedback
app/Services/                  ScheduleService (occurrences, now/next), StreakService, ImportService
database/migrations/           one per table
routes/api.php                 /api/v1 routes
tests/Feature/ tests/Unit/     Pest tests
docs/                          planning docs, openapi.yaml
legacy/                        old code, reference only, deleted at the end of R1
```

## Frontend structure (Loomora_front)
```
src/app/            router, providers, layout, bottom nav
src/features/       todos/, schedule/, today/, auth/, profile/, streak/
src/lib/            api client, storage adapter (guest vs server), date helpers
src/components/ui/  Button, Card, Sheet, Chip, Ring, Checkbox, Input (neo-brutalism)
src/i18n/           en.json (km.json later)
src/styles/         design tokens, tailwind layers
e2e/                Playwright tests
docs/               planning docs
legacy/             old code, reference only
```

## Environments and secrets (never in git)
Backend env: APP_KEY, APP_URL, FRONTEND_URL, DB_CONNECTION=pgsql, DB_URL (Neon direct), GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI, BREVO_API_KEY, MAIL_FROM_ADDRESS, FEEDBACK_TO_EMAIL.
Frontend env: VITE_API_URL.
Each has an `.env.example` with empty values.
