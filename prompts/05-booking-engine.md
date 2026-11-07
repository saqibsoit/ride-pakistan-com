# Prompt 05: Booking engine, holds, packages and credits

Read `AGENTS.md` and `docs/PLAN.md` sections 2, 3 and 5. This is the most critical milestone: correctness over speed.

0. Read `docs/reference/DESIGN-REFERENCES.md`. Wire live data into the pages built in milestone 2: seats left and "Selling fast" on product pages and the homepage "This weekend" strip, the `/schedule` week calendar (filters by product type and trainer, previous/next week, click a slot to open the booking widget pre-filled), and the inline booking widget on `/sessions/{product}` (date, slot, rider count stepper, seats left, continue to checkout).
1. `/book` Livewire flow, mobile-first:
   a. Choose product (single, private, 8-class, 16-class) with clear prices in PKR.
   b. Choose riders (logged in) or enter rider details (guest → login by OTP before payment).
   c. Choose slot(s) from a weekend calendar showing seats left. Single/private: one slot. Packages: buying a package does not require picking all dates now; the customer may book the first session immediately (optional) and the rest later from their account using credits.
   d. Review → create order → go to checkout.
2. Actions:
   - `HoldSeats` (transaction + `lockForUpdate()` on slot; confirmed + unexpired holds ≤ capacity; private requires an empty slot and holds full capacity).
   - `CreateOrder` (reference generator `RP-XXXXXX`, unambiguous characters, unique; amounts computed server-side from products, never from the client).
   - `ReleaseExpiredHolds` (scheduled every minute).
   - `FulfilOrder` (shared by Stripe webhook and bank transfer approval; idempotent): mark paid, convert holds to bookings with unique ticket codes, create credit pack for packages with `valid_from = now`, `expires_at = now + validity_days`, write ledger and audit log.
   - `BookWithCredit` (customer books a slot using package credit: checks validity, credits left, capacity with lock, one booking per rider per slot).
   - `CancelBooking` / `RescheduleBooking` respecting the cutoff setting; credit returned to pack if before cutoff and pack not expired.
3. Admin: manual booking, move a booking to another slot, extend a credit pack (reason required, logged), mark attended/no-show.

Acceptance criteria (tests required):
- Concurrency: two parallel holds for the last seat → exactly one succeeds.
- Private session cannot be booked on a slot with any booking or hold, and blocks the slot once held.
- Price tampering in the request has no effect.
- Credits: cannot book with expired pack, cannot exceed credits, cancellation before cutoff refunds a credit, after cutoff does not.
- `FulfilOrder` run twice creates no duplicates.
- Holds expire and seats return.
