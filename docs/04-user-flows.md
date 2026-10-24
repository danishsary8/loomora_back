# Loomora: User Flows (A4, in progress)

## Flow 1: Daily use (approved layout)
Open app -> Today loads (skeleton, no splash) ->
- Header: date + streak badge + profile
- Schedule strip: NOW / NEXT (tap = full day)
- Progress ring: "3 of 7 done"
- To-do list: Overdue first, then today's items
- Floating + (bottom right) opens a bottom sheet to add a to-do (title field focused, keyboard open; due date and category optional)
- Bottom nav: Today | Week | Focus | Insights

Paths:
1. Check off: tap checkbox -> stamp animation -> ring updates -> first check-off of the day lights the streak badge
2. Add a to-do: tap + -> sheet -> type title -> Add (D25)
3. Edit schedule item: tap strip -> tap item -> "Only today" or "All repeats"

## To map next
Flow 2 first visit | Flow 3 guest to account | Flow 4 schedule setup and week view | Flow 5 editing and deleting | Later releases: focus, notes, insights

## Flow 2: First visit (approved)
Landing page -> "Try it now" -> empty Today (guest) with two equal-weight buttons: "Add a to-do" and "Add to my schedule".
- To-do path: bottom sheet -> title -> Add -> item appears, ring shows 0 of 1.
- Schedule path: form (title, start, end, days) -> Save -> Now/Next strip appears.
- Goal: first item added in under 60 seconds with no signup.
- After the first check-off: stamp celebration. Later, a gentle "Save your plan" prompt (see Flow 3).

## Flow 3: Guest to account (approved trigger)
Trigger: first "Save your plan" prompt appears after 3+ items are checked off or after the first full day, whichever comes first. It is dismissible, returns after a few days, and is always reachable from Profile.
Steps: tap Save your plan -> choose Email or Google -> account created -> guest to-dos, schedule items, streak and settings move to the account -> toast "Your plan is saved".
Failure: if signup or migration fails, guest data stays on the device unchanged and the user can retry.
Warning shown to guests in Profile: "Data lives on this device only."

## Flow 4: Schedule setup and Week view (approved scope)
Week screen shows the fixed schedule only; to-dos stay on Today.
- Phone: day strip (Mon-Sun) + one day at a time + week overview toggle. Tablet/desktop: 7 columns.
- Add: tap + on Week -> form (title, start, end, repeat days, category color). Ticking several days creates one item.
- Edit: tap item -> edit sheet. From Today: "Only today" or "All repeats". From Week: edits the repeat itself.
- Copy day: "Copy day to..." choose target days.
- Delete: removes all repeats, or only this day (skip once).
