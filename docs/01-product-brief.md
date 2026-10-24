# Loomora: Product Brief and Decisions (v0.1)

Status: planning. Stage A (Plan) in progress. No code until A1 to A8 are agreed.

## 1. Product

**Name:** Loomora
**Promise:** Plan your day, focus on it, and see how it really went.
**Pillars:** Plan, Focus, Review. Every feature must serve one of these. Anything else goes to "Later".

**Design-first user:** a person with a mostly repeating week (student or worker). The app is open to everyone, but decisions are tested against this person.

**Goal:** equal weight on (a) a strong portfolio/CV project and (b) a product real people want to use.

**Success targets for the first public release:**
1. A new user builds a usable day and checks off a block in under 60 seconds, with no signup.
2. At least 10 real people use it for a full week.

**Platform:** mobile-first web app, installable (PWA).
**Languages:** English first. Khmer in phase 4. Translation system built in from day one.
**Visual identity:** own neo-brutalism on a cream base (thick borders, hard offset shadows, press-down interactions, flat category colors, script headings).

## 2. Scope and releases

All features below are in scope, delivered as staged releases. Each release is usable on its own.

| Release | Contents |
|---|---|
| R1 Plan | Blocks, full week grid, repeat and one-day overrides, Today screen (Now / Next / Later), check-off, progress ring, today's goals, guest mode then account, feedback button |
| R2 Focus | Focus timer on a block, countdown timer, stopwatch, time tracking |
| R3 Review | Notes on blocks, streak, analytics, weekly recap, Plan vs Reality |
| Phase 4 | Khmer language, accessibility and performance polish |

Inspired by daycraft.live (features only). Structure, flow, wording and visuals are Loomora's own. Themes are intentionally left out: one strong identity.

## 3. Decisions log

| # | Decision |
|---|---|
| D1 | Promise is "Plan, focus, review" |
| D2 | Success targets: 60-second first use AND 10 real users for a week |
| D3 | Platform: mobile-first PWA |
| D4 | Language: English first, Khmer in phase 4 |
| D5 | Scope split into R1, R2, R3 (approved) |
| D6 | First-visit flow: Try it now (guest), pick a starter template, land on a filled Today screen with a 3-step coach mark |
| D7 | Days model: full week grid as the planning view |
| D8 | On phones the grid becomes a one-day view with a day strip, plus a week overview toggle. Tablet and desktop show the full 7-column grid |
| D9 | Blocks can be added to several days at once, with a "Copy day to..." action |
| D10 | Editing a repeating block from Today offers "Only today" or "All repeats" |
| D11 | Data model: a block has a time and the days it repeats. The grid is only a view of that data |

## 4. First-visit flow (R1)

1. Landing page, tap "Try it now" (guest mode, no signup)
2. Pick a starter: Student, Work, Mixed, or Blank
3. Land on Today with a filled-in day and a 3-step coach mark (check off, add a block, open the menu)
4. First check-off gets a celebration (neo-brutalist stamp animation)
5. After a few uses, a gentle "Save your plan" prompt creates an account and migrates guest data

## 5. Open questions (to decide in the next steps)

- What counts as a "done" day for the streak?
- Which starter templates and what blocks each contains?
- Category model: fixed set of colors or user-defined?
- Daily-use flow: exact layout of the Today screen
- Guest-to-account migration rules
- Tech stack, auth method, hosting (A8)
- Data model and API (A6, A7)

## 6. Direction change (v0.2)
Reset by the owner: the app is now a **to-do list + fixed schedule** that answers "what do I have to do today?". Starter templates and the D6 onboarding flow are dropped.

| # | Decision |
|---|---|
| D12 | Streak: a day counts if at least 1 item is checked off |
| D13 | Categories: fixed set of 6 colors |
| D14 | Today screen: the to-do list is the main view. The fixed schedule is a small strip on top |
| D15 | To-do item is full-featured: title, due date, subtasks, repeat, reminders (priority/category/note to confirm) |

Open: first-visit flow, how schedule strip behaves, how to-dos repeat, reminders delivery (PWA push), what happens to R1/R2/R3 scope.
| D16 | Schedule strip shows Now + Next only; tap expands the full day |
| D17 | Schedule setup: add items one by one with time and repeat days (quick-type is Later) |
| D18 | Unfinished to-dos auto-carry to the next day with an "Overdue" badge |
| D19 | Reminders: in-app first, push notifications in a later release |
| D20 | First visit: guest mode, straight to an empty Today with two big buttons ("Add a to-do", "Add to my schedule") |
| D21 | Releases re-cut: R1 Today (to-dos, schedule, Today screen, guest, account); R2 Repeat, subtasks, reminders; R3 Focus (timer, stopwatch, countdown); R4 Review (streak, notes, analytics, weekly recap); then Khmer and polish |

## 7. daycraft.live feature parity checklist (D22)
Daily planning -> schedule + Now/Next (R1) | Today's goals -> to-do list (R1) | Guest mode + upgrade -> local data migrates on signup (R1) | Accounts -> email + Google (R1) | Preferences saved (R1) | Focus timer -> on a to-do/schedule item (R3) | Countdown 25/50/90 + custom (R3) | Stopwatch with laps, 1/100 s (R3) | Time tracking from sessions (R3) | Timer history (R3) | Timer-end sound with mute (R3) | Notes (R4) | Analytics (R4) | Themes: intentionally not copied (one neo-brutalism identity).
Extras beyond daycraft.live: streaks, Now/Next, Overdue carry-over, subtasks, repeat, reminders, Plan vs Reality, Khmer.
| D22 | daycraft.live parity checklist added (section 7); one neo-brutalism identity, no multi-theme |
| D23 | A2 feature list approved (docs 02). A3 R1 user stories drafted (docs 03) |
| D24 | Streak: days with no items are skipped and never break the streak |
| D25 | Add a to-do: floating + opens a bottom sheet. Bottom nav: Today, Week, Focus, Insights (docs 04 in progress) |
| D26 | First-visit empty Today shows both buttons with equal weight |
| D27 | 'Save your plan' prompt after 3+ check-offs or the first full day; dismissible; always in Profile |
| D28 | Week screen = fixed schedule only; add/edit/copy/delete there |
| D29 | A5 screen map drafted (13 screens, bottom nav Today/Week/Focus/Insights). Claude Code prompt 00 (docs setup) written |
