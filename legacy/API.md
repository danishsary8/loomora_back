# Daycraft API v1

Daycraft exposes a versioned, authenticated, user-owned routine API. Routes are prefixed with `/api/v1`.

Success: `{ "data": ..., "meta"?: ... }`

Failure: `{ "error": { "code": "...", "message": "...", "details"?: ... } }`

Send protected requests with `Authorization: Bearer <token>`. Schedule mutations additionally require a verified email. Foreign user-owned resources return `404` without disclosing their owner.

## Public account routes

- `GET /health`
- `POST /auth/register` — requires `name`, `email`, confirmed strong `password`, and `terms_accepted: true`.
- `POST /auth/login`
- `GET /auth/google/redirect`
- `GET /auth/google/callback` — validates the one-time OAuth state and redirects with a short-lived handoff code.
- `POST /auth/google/exchange` — exchanges the single-use handoff code for a Sanctum token.
- `GET /email/verify/{id}/{hash}` — temporary signed verification URL.
- `POST /password/forgot` — always returns the same non-enumerating response.
- `POST /password/reset` — consumes an expiring token and revokes all existing Sanctum tokens.

## Protected account routes

- `GET /auth/me`
- `POST /auth/logout`
- `POST /email/verification-notification`
- `DELETE /account` — permanently deletes the user and all owned data. Re-authentication is required and depends on how the account signs in:
  - Accounts with a password send `{ "password": "<current password>" }`. A wrong or missing password returns `422 validation_failed` with `error.details.password`.
  - Google-created accounts store a null password, so they send `{ "confirmation": "DELETE" }` instead. Without this fallback those accounts could never be deleted.

  Clients can choose the correct field from `has_password` on the user resource.

Password and first-time Google registration record `terms_accepted_at`. Google matches a verified provider email to an existing account instead of creating a duplicate.

## Day groups and schedule

- `GET /day-groups`
- `POST /day-groups` with `name`, optional hex `color`, optional `sort_order`, and `weekdays` (`0` Sunday through `6` Saturday).
- `PATCH /day-groups/{id}`
- `DELETE /day-groups/{id}?confirm=true` — explicitly confirms cascading child deletion.
- `GET /schedule/day-groups/{id}`
- `GET /schedule/today?date=YYYY-MM-DD`

The today response returns every owned group assigned to that weekday plus flattened `timeline` and `checklist` arrays. When none applies it returns `assigned: false`, `groups: []`, and a readable message; this is not an error.

## Timeline entries

- `GET /day-groups/{id}/timeline-entries`
- `POST /day-groups/{id}/timeline-entries`
- `PATCH /timeline-entries/{id}`
- `DELETE /timeline-entries/{id}`

Writable fields: `start_time`, `end_time`, `description`, `category`, `sort_order`. Times use 24-hour `HH:MM`; overnight ranges are valid.

Assignable categories are Career, Health, Language, Life, and Rest.

`Faith` is **retired**: it is rejected with `422 validation_failed` on create and update, but entries stored before retirement keep the value and still read back normally. Omitting `category` on a `PATCH` preserves a retired value, so historical entries remain editable. Retired values are declared in `App\Enums\Category` and must not be deleted — the `category` column is a plain string cast to that enum, so removing a case would break reads for existing rows.

## Checklist

- `GET /day-groups/{id}/checklist-items`
- `POST /day-groups/{id}/checklist-items`
- `PATCH /checklist-items/{id}`
- `DELETE /checklist-items/{id}`
- `GET /checklist/{YYYY-MM-DD}`
- `PUT /checklist/{YYYY-MM-DD}` with `{ "checked_ids": [1, 2] }`

Checklist item IDs are numeric and user-owned. Completion logs are isolated by account, item, and calendar date.

## Settings and optional prayer times

- `GET /notification-settings`
- `PUT /notification-settings` with optional `enabled` and `minutes_before`.
- `GET /prayer-times/{YYYY-MM-DD}?lat=…&lng=…`

Prayer times are an optional enhancement and never create schedule content. If the upstream provider is unavailable, the endpoint returns a successful envelope with `timings: null`, `meta.source: "unavailable"`, and a warning so the dashboard remains usable.

## Limits and caching

Registration, login, password reset, verification resend, and OAuth entry points have endpoint-specific throttles. Authenticated API traffic is limited per user and `429` responses include `Retry-After`. `/schedule/today` is cached for 60 seconds with immediate version invalidation after every group, entry, or checklist-item mutation.

## First-party analytics

- `POST /analytics/page-view` — public, rate-limited; accepts only `path` and an optional UUID `idempotency_key`.
- `POST /analytics/event` — authenticated; accepts an allow-listed notification-permission event and required UUID idempotency key.
- `GET /internal/analytics` — authenticated and restricted to `ANALYTICS_ADMIN_EMAILS`.

Signup, activation, checklist-check, group-deletion, and account-deletion events are emitted by their backend services only after successful state changes. Analytics rows contain no user ID, email, free text, IP address, or user agent.
