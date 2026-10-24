# Loomora: User Stories, Release 1 (A3, v0.1)

Format: **As a** user, **I want** X **so that** Y. Acceptance: Given / When / Then.
Persona: Sokha, a student with a mostly repeating week, on a phone.

## Epic 1: First visit and guest mode
**US-1.1** As a new visitor, I want to try the app with no signup so that I can see if it works for me.
- Given I open Loomora for the first time, When I tap "Try it now", Then I land on an empty Today screen without entering anything.
- Given I am a guest, When I close and reopen the app on the same device, Then my data is still there.

**US-1.2** As a new guest, I want a clear first action so that I know what to do.
- Given Today is empty, Then I see two big buttons: "Add a to-do" and "Add to my schedule".
- Given I add my first item, Then the empty prompt disappears.

## Epic 2: To-dos
**US-2.1** As a user, I want to add a to-do quickly so that I can capture it before I forget.
- Given I tap "Add a to-do", When I type a title and tap Add, Then it appears in Today's list. Due date and category are optional.
- Target: first to-do added in under 10 seconds.

**US-2.2** As a user, I want to check off a to-do so that I see my progress.
- Given a to-do in my list, When I tap its checkbox, Then it is marked done with a stamp animation and the progress ring updates.
- Given I tapped by mistake, When I tap again, Then it returns to not done.

**US-2.3** As a user, I want to edit or delete a to-do so that I can fix mistakes.
- Given a to-do, When I open it, Then I can change title, due date, category, or delete it (with undo for 5 seconds).

**US-2.4** As a user, I want unfinished to-dos to carry over so that nothing gets lost.
- Given a to-do was not done by midnight, When I open the app next day, Then it appears in Today with an "Overdue" badge.

## Epic 3: Fixed schedule
**US-3.1** As a user, I want to add a schedule item with a time and repeat days so that my week is set once.
- Given I tap "Add to my schedule", When I enter title, start and end time, and tick days (e.g. Mon to Fri), Then it appears on those days.
- Given I tick several days, Then one item is created for all of them.

**US-3.2** As a user, I want to see Now and Next at the top of Today so that I know what is happening.
- Given schedule items exist today, Then the strip shows the current item (Now) and the following one (Next).
- Given nothing is happening now, Then Now shows "Free" and Next shows the next item.
- Given I tap the strip, Then the full day's schedule opens.

**US-3.3** As a user, I want to change a schedule item for one day only so that exceptions do not break my routine.
- Given a repeating item, When I edit it from Today, Then I choose "Only today" or "All repeats".
- Given "Only today", Then other days stay unchanged.

**US-3.4** As a user, I want a week view so that I can see my whole routine.
- Given I am on a phone, Then I see a day strip (Mon to Sun) and one day at a time, with a week overview toggle.
- Given I am on tablet or desktop, Then I see all 7 columns.

## Epic 4: Streak
**US-4.1** As a user, I want a streak so that I stay consistent.
- Given I check off at least 1 item today, Then today counts and the streak goes up by 1.
- Given I check off nothing for a full day, Then the streak resets to 0 the next day.
- Given the day has no items at all, Then it is not counted as a miss (rule to confirm in A6).

## Epic 5: Account
**US-5.1** As a guest, I want to save my plan to an account so that I do not lose it.
- Given I am a guest, When I sign up with email or Google, Then all my guest to-dos, schedule items, and streak move to the account.
- Given signup fails, Then my guest data stays on the device untouched.

**US-5.2** As a returning user, I want to sign in on another device so that I see the same data.

**US-5.3** As a user, I want to sign out and delete my account so that I control my data.

## Epic 6: Feedback and install
**US-6.1** As a user, I want a feedback button so that I can report problems.
**US-6.2** As a user, I want to install Loomora on my home screen so that it opens like an app.

## Open for A6
Time zone handling for "midnight", streak rule on empty days, what "Overdue" does after many days.
