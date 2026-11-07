# Prompt 03: Data model, schedule rules, slots and admin base

Read `AGENTS.md` and `docs/PLAN.md` sections 2, 5 and 6.

1. Create migrations, models, enums, factories and policies for: users (role enum), riders, products, schedule_rules, slots, orders, order_items, booking_holds, bookings, credit_packs, credit_ledger, bank_transfers, payments, stripe_events, leads, lead_notes, settings. Foreign keys, indexes and unique constraints exactly as needed for the queries in later milestones. Money in `*_minor` integer columns.
2. Seed products from PLAN section 2 (single 10,000; private 15,000; 8-class 60,000 / 42 days; 16-class 100,000 / 70 days).
3. Seed schedule rules: Saturday and Sunday, 08:00–10:00 and 15:00–17:00 Asia/Karachi, capacity from the "riders per slot" setting.
4. `GenerateSlots` action + scheduled daily command: create slots for the next 8 weeks from active rules. Idempotent (unique on `starts_at`). Never delete slots that have bookings; rule changes only affect future empty slots. Admin can close or cancel individual slots (cancel requires a reason and notifies booked riders later in milestone 8).
5. Order status transitions in one place (`OrderStateMachine`), invalid transitions throw, every transition writes to activity log.
6. Filament resources: Products, Schedule rules, Slots (calendar view + list, seats used/held/free), Customers & riders, Orders (read-only for now), Settings page, Activity log viewer. Admin role only; staff role gets read access to slots and bookings.
7. Admin auth: enforce MFA for admin and staff, strong password rules, login throttling.

Acceptance criteria:
- Tests: slot generation idempotency, DST-safe Asia/Karachi conversions, rule changes not touching booked slots, state machine transitions (valid and invalid), policies per role.
- Larastan clean at the recorded level.
