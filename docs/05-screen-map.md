# Loomora: Screen Map (A5, v0.1, Release 1)

## Navigation
Bottom nav (phone): **Today | Week | Focus | Insights**. Profile opens from the header avatar.
Focus and Insights show a "Coming soon" screen in R1 (or are hidden until R3/R4: decide in B1).
Tablet/desktop: left sidebar with the same items.

## Screens
| # | Screen | Purpose | Reached from | Key elements |
|---|---|---|---|---|
| S1 | Landing | Explain Loomora, start | Public URL | Promise, "Try it now", Sign in |
| S2 | Today (empty) | First action | Try it now | Two equal buttons: Add a to-do, Add to my schedule |
| S3 | Today | Daily home | Nav, app open | Header (date, streak, avatar), Now/Next strip, progress ring, to-do list (Overdue first), floating + |
| S4 | Add/Edit to-do (bottom sheet) | Create or change a to-do | + on Today, tap a to-do | Title, due date, category color, Save, Delete |
| S5 | Full day schedule (sheet) | See the whole day | Tap the strip | Day's items in time order, edit |
| S6 | Week | Build the fixed routine | Nav | Day strip + one-day list, week overview toggle, + button, Copy day to... |
| S7 | Add/Edit schedule item (sheet) | Create or change a schedule item | + on Week, tap an item | Title, start, end, repeat days, category color; "Only today / All repeats" choice when from Today |
| S8 | Copy day (sheet) | Duplicate a day | Week menu | Pick target days |
| S9 | Profile | Account and data | Header avatar | Name, streak, Save your plan (guests), Sign out, Delete account, Feedback |
| S10 | Sign in / Sign up | Account | Save your plan, Landing | Email, Google |
| S11 | Save your plan (prompt sheet) | Ask guests to sign up | After 3 check-offs | Benefits, Save, Not now |
| S12 | Feedback (sheet) | Report a problem | Profile | Message, Send |
| S13 | Legal and 404/error | Required pages | Footer | Privacy, Terms, Not found |

## Connections
Landing -> Today (empty) -> Today -> [To-do sheet | Day schedule sheet | Week | Profile]
Week -> [Schedule item sheet | Copy day]. Profile -> [Sign up, Feedback, Delete]. Save your plan -> Sign up -> Today.

## States every screen needs
Loading (skeleton), empty, error with retry, offline. Phone first (375 px), then tablet and desktop.

## Later releases (placeholders)
Focus: timer, countdown, stopwatch, history (R3). Insights: analytics, weekly recap, notes (R4). Subtask and repeat controls inside S4 (R2).
