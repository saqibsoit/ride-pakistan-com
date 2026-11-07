# Prompt 13: QA and launch checklist

Read `AGENTS.md` and `docs/PLAN.md`. Produce and work through `docs/LAUNCH-CHECKLIST.md`.

1. End-to-end tests (Pest browser testing or Dusk) for: single session card payment, private session, 8-class package purchase then booking with credits, bank transfer approve and reject, hold expiry, cancellation before and after cutoff, lead form, login by OTP.
2. Content check: every price, schedule, trainer fact and policy matches `docs/PLAN.md` and settings; legal pages complete (privacy, terms, refund policy); Stripe requirements met (contact details, refund policy, product descriptions).
3. SEO: Search Console + Bing Webmaster verified, sitemap submitted, structured data tested with Google Rich Results Test, Google Business Profile details consistent, `llms.txt` accurate.
4. Accessibility: keyboard-only and screen reader pass on booking flow; axe scan clean.
5. Performance and security targets from PLAN re-verified on production.
6. Stripe switched to live keys, live webhook endpoint configured and tested with a real small payment and refund.
7. Staff guide `docs/STAFF-GUIDE.md`: verifying transfers, managing slots, check-in, handling leads, refunds. Written for non-technical staff.

Output: the completed checklist with pass/fail per item and any open risks.
