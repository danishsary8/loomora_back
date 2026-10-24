# Daycraft integration notes

The React client uses `VITE_API_URL` and sends a Sanctum bearer token through the shared Axios client. Schedule state is never globally seeded: the dashboard first loads `/day-groups`, presents the builder when the result is empty, and then reads and mutates user-owned groups directly.

The canonical weekday convention is JavaScript’s `Date.getDay()`: `0` is Sunday and `6` is Saturday. A weekday may belong to multiple groups. `/schedule/today` returns every matching group and flattened `timeline`/`checklist` arrays for live highlighting and reminders.

Deletes are explicit. The client confirms destructive actions and the day-group API additionally requires `confirm=true`; database foreign keys cascade child entries, checklist items, and checklist logs.

Run verification with:

```bash
cd backend
php artisan migrate:fresh
php artisan test
cd ..
npm run build
```
