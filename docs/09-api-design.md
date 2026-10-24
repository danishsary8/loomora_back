# Loomora: API Design (A7, v1.0)

Base URL: `/api/v1`. JSON only. Auth: Laravel Sanctum **bearer tokens** (the frontend on Vercel and the API on Render are on different domains, so cookies are not used). Token expiry: 30 days. Dates are `YYYY-MM-DD`, times `HH:MM`, timestamps ISO 8601 UTC. The user's local "today" is sent by the client as `?date=YYYY-MM-DD` and checked against the user's time zone.

Errors use one shape: `{ "message": "...", "errors": { "field": ["..."] } }` with the right HTTP status (401, 403, 404, 422, 429).

## Auth
| Method | Path | Purpose |
|---|---|---|
| POST | /auth/register | name, email, password, timezone. Sends a verification email |
| POST | /auth/login | email, password, returns token + user |
| POST | /auth/logout | revoke current token |
| GET | /auth/google/redirect?intent=sign_in or sign_up | start Google OAuth |
| GET | /auth/google/callback | finish Google OAuth, redirect to the frontend with a one-time code |
| POST | /auth/google/exchange | one-time code to token + user |
| POST | /auth/email/verify | verify email token |
| POST | /auth/email/resend | resend verification |
| POST | /auth/password/forgot | send reset email |
| POST | /auth/password/reset | token + new password |
| GET | /me | current user |
| PATCH | /me | name, timezone |
| POST | /me/survey | goal, heard_from (skippable) |
| DELETE | /me | delete account (password or Google re-auth confirmation) |

## To-dos
| Method | Path | Purpose |
|---|---|---|
| GET | /todos?date= | the Today list: due today, undated not done, overdue (with an `overdue` flag) |
| POST | /todos | create (title, category, due_date) |
| PATCH | /todos/{id} | edit title, category, due_date |
| POST | /todos/{id}/done | set done_at |
| DELETE | /todos/{id}/done | clear done_at |
| DELETE | /todos/{id} | delete |

## Schedule
| Method | Path | Purpose |
|---|---|---|
| GET | /schedule/day?date= | occurrences for the date (exceptions applied, check-in state) |
| GET | /schedule/week?start= | occurrences for 7 days |
| GET | /schedule/now?date=&time= | `{ now, next }` |
| GET | /schedule/items | the repeat definitions |
| POST | /schedule/items | create (title, category, start_time, end_time, days[]) |
| PATCH | /schedule/items/{id} | all repeats |
| DELETE | /schedule/items/{id} | delete all repeats |
| PUT | /schedule/items/{id}/exceptions/{date} | only that day: type skip or override |
| DELETE | /schedule/items/{id}/exceptions/{date} | restore that day |
| POST | /schedule/items/{id}/checkins/{date} | mark occurrence done |
| DELETE | /schedule/items/{id}/checkins/{date} | undo |
| POST | /schedule/copy-day | from_weekday, to_weekdays[] |

## Streak, import, feedback
| Method | Path | Purpose |
|---|---|---|
| GET | /streak?date= | current_streak, longest_streak, today_counted |
| POST | /import | guest data import (idempotent, transactional) |
| POST | /feedback | message |
| GET | /health | `{ "status": "ok" }` (public) |

## Rules
- Every list and write endpoint is scoped to the authenticated user. Never accept `user_id` from the client.
- Rate limits: auth routes 10 per minute per IP, others 120 per minute per user.
- CORS allows only the frontend origins from the `FRONTEND_URL` env variable.
- API resources return `id`, fields above and `created_at`. OpenAPI file at `docs/openapi.yaml`, kept in sync.
- Later releases add endpoints (subtasks, repeats, reminders, timers, notes, analytics) in their own docs.
