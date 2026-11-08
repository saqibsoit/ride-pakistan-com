# Prompt 10a: Admin catalogue and schedule control (audit + gaps)

Read `CLAUDE.md`, `docs/PLAN.md` and `docs/reference/DESIGN-REFERENCES.md`. Start in plan mode.

Goal: an admin can run the whole offer from the admin panel without a developer.

1. Audit what exists and report a table (feature | exists? | gap). Check that an admin can:
   - Create, edit, reorder, feature, hide (deactivate) and archive products of every type: single, private, package. For packages: sessions count, validity days, price in PKR, per-session price shown automatically.
   - Edit each product's public content: slug, short and long description, who it’s for, what to bring, what’s included, good to know, duration, group size, cover image, gallery, optional video URL, category, SEO title and description.
   - Manage schedule rules (days, times, capacity, valid from/to, seasonal changes) and see a preview of the slots a rule will generate before saving.
   - Create, edit, close, reopen and cancel manual slots, including weekday one-offs and private-only slots.
   - Edit every setting (bank details, map pin, arrival time, hold windows, cutoffs, riders per slot, notice periods) with help text explaining each one.
2. Build every gap found. Rules:
   - Price or validity changes never affect orders already placed (orders keep their own price and validity snapshot).
   - A product with orders can be archived, never deleted.
   - Every change is in the audit log and clears the page cache.
3. Add an "Admin guide" page inside the panel (read-only) explaining how to add a new package, change seasonal timings and add a one-off session.

Acceptance: tests for price snapshots, archive vs delete, rule preview, manual slots; the audit table in your summary. Work on a feature branch.
