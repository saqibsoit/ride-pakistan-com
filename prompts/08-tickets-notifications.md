# Prompt 08: Tickets, check-in and notifications

Read `AGENTS.md` and `docs/PLAN.md`.

1. Ticket per booking: unique non-guessable `ticket_code`, QR code (SVG) linking to `/checkin/{code}`. Ticket page in the account (printable) and attached to the confirmation email.
2. `/checkin/{code}`: staff-only (login + role), mobile-first, shows rider name, slot, product, credit pack status, and a big "Check in" button that marks the booking attended. Clear warnings for wrong date, cancelled or already-checked-in tickets.
3. Notifications (queued, Markdown mail with brand styling, plain-text fallback):
   - Order confirmed (with ticket(s) and the exact location pin from settings)
   - Bank transfer received / approved / rejected / expired
   - Reminder 24 hours before the session (with what to wear and arrival time)
   - Slot cancelled by admin (with reschedule link; credit returned or refund instructions)
   - Credit pack expiring in 7 days
   - Post-session thank-you with a request for a Google review
4. Notification channel interface ready for WhatsApp (GT360) later: implement email now and a no-op WhatsApp channel behind a feature flag.
5. Admin can resend any notification from the order or booking page.

Acceptance criteria:
- Tests: correct notifications per state change, reminders scheduled correctly in Asia/Karachi time, check-in authorisation and edge cases, QR content.
