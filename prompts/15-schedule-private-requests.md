# Prompt 15: Bulk schedule editor and private session requests

Read `CLAUDE.md`, `docs/PLAN.md` and `docs/reference/DESIGN-REFERENCES.md`. This touches bookings and payments: start in plan mode, show the plan and the data/state changes, and wait for approval. Work on a feature branch; run all checks, push and open a PR when done.

## A. Bulk schedule editor (admin)

1. A "Bulk schedule" screen: pick a date range, days of the week, start time, end time, capacity, trainer and slot type (group / private-only). Actions: create slots, update matching slots (time, capacity, trainer), close or cancel slots.
2. Preview before saving: a table of slots to create, change, close or cancel, each showing booked riders. Nothing is saved until confirmed.
3. Rules: capacity can't go below seats already taken. Changes run in transactions with slot row locks. Every change is audit-logged and clears the page cache.
4. **Booked slots that change time or date:** each affected customer gets one email with the old and new time and three options (signed links, expiring at the new start time): accept the new time, move to another slot, or cancel for a credit (package bookings) or a refund request flagged for admin (paid bookings). No response means the booking stays at the new time. Trainers of affected sessions get a summary email. Cancelling slots uses the existing cancellation flow.
5. Show a summary after saving: slots changed, customers notified, any failures.

## B. Private sessions on any date and time

Current behaviour stays: booking a private session on an existing admin slot needs no approval and goes straight to payment.

New: **private session requests** for a custom date and time.
1. On the private session page, the customer can request a date and start time (2-hour session) instead of picking a slot, plus number of riders (up to the slot capacity setting) and a note. Allowed hours 7:00 am to 6:00 pm Pakistan time, at least 48 hours ahead (both settings). Requires sign-in; email and WhatsApp number required.
2. The request goes to admins (and the assigned trainer when trainer accounts exist) by email and appears in an admin "Private requests" queue. **If nobody approves or declines within 2 hours, the request expires** and the customer is told (setting, default 2 hours).
3. **Approve:** creates a private-only manual slot at that time (blocking it for everyone else), holds it, and emails the customer a payment link. **The customer must pay within 2 hours of approval** (setting, default 2 hours), by card or bank transfer (transfer upload must happen within the window; verification follows the existing flow). If unpaid in time, the hold and slot are released and the customer is told.
4. **Decline:** reason required, optional suggested alternative times; customer emailed.
5. Overlap checks: approval is refused if the time overlaps any existing slot or private session (with a configurable buffer, default 30 minutes) or the horse/trainer limits.
6. Price PKR 15,000 per rider, from the product, snapshot on the order as today. Fulfilment only through FulfilOrder.
7. Customer account shows request status (requested, approved – pay by [time], expired, declined, paid). All transitions in a state machine, audit-logged.
8. Every email-sent message on screen includes the "check your spam folder" notice from prompt 14.

Acceptance: tests for request expiry at 2 hours, payment window expiry at 2 hours, overlap refusal, concurrency (two approvals for overlapping times), notifications, bulk editor preview vs. applied changes, customer response links (accept/move/cancel), and browser tests for the request → approve → pay flow.
