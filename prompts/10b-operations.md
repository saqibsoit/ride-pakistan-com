# Prompt 10b: Daily operations (run sheet, horses, trainer role, dashboard, exports)

Read `CLAUDE.md`, `docs/PLAN.md` and `docs/reference/DESIGN-REFERENCES.md`. Start in plan mode.

1. Horses: `horses` table (name, photo, temperament notes, suitable levels: beginner/intermediate/confident, max sessions per day, active, retired). Admin CRUD. Availability per date (rest days, vet days).
2. Horse assignment: on each booking, staff or trainer assign a horse. Warn (not block) on level mismatch; block when a horse exceeds its max sessions per day or is unavailable. Show assignment on the run sheet and in the rider’s history.
3. Trainer role: new role `trainer`. Trainers see their own sessions, run sheets, riders (name, level, notes, emergency contact) and can check in, mark no-show, assign horses and write session notes. No access to payments, settings, leads or other admin areas. MFA required like staff.
4. Run sheet: mobile-first page per slot (admin, staff, trainer): riders, age bracket, experience, notes, emergency contact (tap to call), assigned horse, check-in buttons, payment status (paid / credit / needs review). Printable version.
5. "Today" dashboard (admin home): today’s and this weekend’s sessions with occupancy, transfers waiting (oldest first), leads due for follow-up today, new leads this week, revenue this month vs last month, credit packs expiring in 14 days. Each card links to the filtered list.
6. Exports (admin only): CSV and Excel for orders, payments, refunds, bookings and leads, filtered by date range. Money in PKR with the reference. Logged in the audit trail.

Acceptance: policy tests per role (trainer cannot see payments or leads), horse limit tests, dashboard numbers tested against fixtures, export content tests, browser smoke test of the run sheet. Work on a feature branch.
