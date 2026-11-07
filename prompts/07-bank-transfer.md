# Prompt 07: Bank transfer and manual verification

Read `AGENTS.md` and `docs/PLAN.md` section 3.2.

1. `/checkout/{reference}/transfer`: shows bank details from settings with copy buttons, the amount in PKR, the order reference as the payment reference, and the hold deadline (countdown, Asia/Karachi time). Selecting this method moves the order to `awaiting_transfer` and extends holds to the bank transfer window.
2. Upload form: screenshot or PDF (required, ≤ 5 MB, jpg/png/webp/pdf, MIME sniffed, images re-encoded and EXIF stripped, random filename on the private disk), optional transaction ID. Moves order to `awaiting_verification`. Customer can replace the file until it is reviewed.
3. Emails: to the customer ("we’ve received your transfer, we’ll confirm shortly") and to admins (new transfer to verify).
4. Filament "Verify transfers" page: queue sorted by deadline, shows screenshot via signed temporary URL, amount expected, reference, customer, rider(s), slot(s). Actions:
   - Approve → `FulfilOrder` (records verifier and time).
   - Reject → reason required → releases seats, emails customer.
   - Extend hold (reason required).
5. Scheduled job: when the window passes without upload or approval, expire the order, release seats, email the customer.
6. Dashboard widget: count of transfers waiting and the oldest waiting time.

Acceptance criteria:
- Tests: upload validation (bad MIME, oversize, disguised file), private file never reachable without signed URL, approve fulfils exactly once, reject releases seats, expiry job, staff role cannot approve (admin only).
