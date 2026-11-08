# Prompt 10d: Customer experience (calendar, waitlist, progress, reviews)

Read `CLAUDE.md`, `docs/PLAN.md` and `docs/reference/DESIGN-REFERENCES.md`. Start in plan mode.

1. Add to calendar: booking confirmation email and account booking page offer Google Calendar link, Apple/Outlook `.ics` download (Asia/Karachi, location "Islamabad" plus map pin only for the booked customer). Updated `.ics` on reschedule, cancellation notice on cancel.
2. Waitlist: on a full slot, signed-in customers can join a waitlist (riders count). When seats free up, the first matching person gets an email offer with a hold for N hours (setting, default 2). If they don’t book in time, the offer moves to the next person. Admin can see and manage each slot’s waitlist. No double holds, row locks as in the booking engine.
3. Rider progress: skill checklist managed by admin (default: approach and lead a horse, grooming, tacking up, mounting and dismounting, walk, halt and steer, rising trot, trail riding on varied ground, canter). Trainers tick skills and add a short note after each session. Customers see each rider’s progress in their account, plus a "next steps" suggestion linking to packages.
4. Reviews: after an attended session (in the thank-you email) customers can leave a 1–5 rating and short review on the site, and get the Google review link. Admin moderates (approve, hide, reply). Approved reviews appear on the homepage and product pages, with `AggregateRating` structured data only when there are at least 5 approved reviews and only on pages where Google allows it.

Acceptance: waitlist concurrency and expiry tests, .ics validity tests, progress permissions (trainer edits, customer reads own riders only), review moderation and schema tests, browser smoke tests. Work on a feature branch.
