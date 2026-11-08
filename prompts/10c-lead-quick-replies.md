# Prompt 10c: Instagram DM quick replies and lead follow-up

Read `CLAUDE.md` and `docs/PLAN.md`. Start in plan mode.

1. Quick replies: admin-managed templates (title, body, category: prices, location, timings, booking, packages, private, payment). Bodies support tokens filled from live data: {price:single-session}, {schedule}, {booking_link}, {lead_name}, {product_link:slug}. Admin/staff can preview and copy with one click from the lead page, the board and a standalone "Quick replies" page.
2. Seed templates: first reply (welcome + what a session includes + booking link), prices, timings, location ("Islamabad, exact pin after booking"), private sessions, packages, bank transfer steps, follow-up after no reply.
3. Booking links with tracking: each copied link can include `?ref=ig-dm&lead={id}` so a booking made from a DM link is attributed to that lead automatically.
4. Follow-up: when a lead is moved to "contacted", suggest a follow-up date (default +2 days, setting). Leads due today appear on the dashboard and in a daily 09:00 email digest to admins and staff.
5. Log which template was copied on the lead’s timeline.

Acceptance: token rendering tests (always matches current prices and schedule), attribution from tracked links, digest content tests. Work on a feature branch.
