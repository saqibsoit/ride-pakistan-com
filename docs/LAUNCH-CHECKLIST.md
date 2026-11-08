# Launch checklist (milestone 13)

Every item from `prompts/13-launch.md`, with its state. Work through the "Needs staging" items once staging exists (`docs/DEPLOY.md` §1 and §13), then the "Needs production" items on launch day.

Status key:

- **Done**: finished and checked in this repository (evidence given).
- **Needs staging**: can only be checked on the staging server (real HTTPS, Cloudflare, cron, Stripe test webhook).
- **Needs production**: can only be done on the live site or with live accounts.
- **Needs owner**: waiting for a business decision or content (see "Open questions" below).

Last updated: 2026-11-09 (branch `feat/m13-launch-prep`).

---

## 1. End-to-end tests

Pest browser tests (Playwright Chromium, phone viewport unless noted), run with the rest of the suite (`tests/Browser`, group `browser`). Payments run against the in-memory Stripe (`tests/Support/FakeStripeGateway.php`): the browser goes through checkout and "Pay by card", and a correctly signed webhook confirms the payment through `FulfilOrder`. Every action is sent once and every wait ends as soon as its result appears (no fixed timeouts).

| # | Journey | Status | Evidence |
|---|---|---|---|
| 1.1 | Single session paid by card (product page → /book → checkout → card → webhook → ticket) | Done | `tests/Browser/CardBookingJourneyTest.php` |
| 1.2 | Private session paid by card; the slot is blocked for everyone else | Done | `CardBookingJourneyTest.php` |
| 1.3 | 8-class package bought by card, then a session booked with its credits | Done | `CardBookingJourneyTest.php` |
| 1.4 | Bank transfer: choose transfer, upload proof, admin approves in Verify transfers | Done | `tests/Browser/BankTransferJourneyTest.php` (see note A) |
| 1.5 | Bank transfer: admin rejects with a reason; seats released; customer sees the reason | Done | `BankTransferJourneyTest.php` |
| 1.6 | Hold expiry: transfer hold with no proof, and an unpaid card hold, expire at the deadline (`holds:release`) | Done | `BankTransferJourneyTest.php` |
| 1.7 | Cancellation before the cutoff (credit back) and after it (credit used) | Done | `tests/Browser/BookingChangesJourneyTest.php` |
| 1.8 | Promo code and gift voucher at checkout: fully covered ("Confirm booking"), and part covered with the rest by card | Done | `tests/Browser/CheckoutDiscountsTest.php` |
| 1.9 | Waitlist: a cancellation offers the seat; the waiting customer books and pays it | Done | `BookingChangesJourneyTest.php` |
| 1.10 | Lead form (/contact) creates a new lead | Done | `tests/Browser/LeadAndLoginJourneyTest.php` |
| 1.11 | Login by emailed one-time code (wrong code first) | Done | `LeadAndLoginJourneyTest.php` |
| 1.12 | Staff check-in on the run sheet (and the trainer journey: horse plus check-in) | Done | `tests/Browser/RunSheetTest.php` |
| 1.13 | Card payment with Stripe's real hosted page and a real test card | Needs staging | Pay a staging order with `4242 4242 4242 4242`; the webhook must return 200 through Cloudflare (`DEPLOY.md` §13). |
| 1.14 | Real file upload from a phone's photo library on the transfer page | Needs staging | See note A. |

**Note A.** The browser plugin's in-process test server (pest-plugin-browser 4.3) doesn't forward multipart form bodies to Laravel yet, so the proof upload step in 1.4 and 1.5 goes through Laravel's HTTP test client as the same signed-in customer (same route, validation and private disk). The browser does every other step. Check one real upload from a phone on staging (1.14).

**Bug found by these tests:** the "Transaction ID" field on the order and gift voucher transfer pages read "Transaction ID (optional) (optional)". Fixed in its own commit, with regression tests.

---

## 2. Content check

| # | Item | Status | Notes |
|---|---|---|---|
| 2.1 | Prices, sessions and validity match PLAN §2 (10,000 / 15,000 per rider; 60,000 for 8 in 6 weeks; 100,000 for 16 in 10 weeks) | Needs staging | Seeded by `ProductSeeder`; entity facts come from data (`BusinessFacts`). Check the live pages, JSON-LD and `/llms.txt` after the staging seed and any admin edits. |
| 2.2 | Schedule matches (Sat and Sun, 08:00–10:00 and 15:00–17:00 Pakistan time) | Needs staging | `ScheduleRuleSeeder`; check `/schedule` and the home page strip. |
| 2.3 | Trainer facts (Omar Saleem; biography) | Needs owner | OPEN-QUESTIONS 5.1, 5.2. |
| 2.4 | Policies shown match settings (cutoff, hold windows, riding rules) | Needs staging | Settings page vs `/prices`, product pages, `/faq`, `/llms.txt`. |
| 2.5 | Privacy policy published | Needs owner | Unpublished outline (OPEN-QUESTIONS 7.1, 12.1). **Blocks launch with tracking.** |
| 2.6 | Terms and conditions published | Needs owner | Unpublished outline (7.1). |
| 2.7 | Refund policy published | Needs owner | Empty (1.4 / 10.3). **Blocks live card payments.** |
| 2.8 | Stripe requirements: contact details, refund policy, product descriptions on the site | Needs owner | Contact email (5.4) and refund policy (1.4) still open; product descriptions are live. |
| 2.9 | Staff guide `docs/STAFF-GUIDE.md` for non-technical staff and trainers | Done | Transfers, slots and seasonal timings, one-off and private sessions, check-in and run sheet, horses, leads and quick replies, weather alerts, refunds (card and bank), vouchers and promo codes. |

---

## 3. SEO

| # | Item | Status | Notes |
|---|---|---|---|
| 3.1 | Google Search Console verified (domain property) | Needs production | DNS TXT through Cloudflare. |
| 3.2 | Bing Webmaster Tools verified | Needs production | Can import from Search Console. |
| 3.3 | Sitemap submitted (`/sitemap.xml`) in both | Needs production | Generated by `GenerateSitemap`; check that legal pages appear once published. |
| 3.4 | Structured data passes Google Rich Results Test (home, a product page, `/faq`, the trainer page, a guide) | Needs staging | Staging is noindex, but the Rich Results Test can fetch it if Cloudflare lets Google through; otherwise test on production. |
| 3.5 | Google Business Profile details consistent (name, area "Islamabad", hours, phone, website) | Needs owner | Location shown as Islamabad only (PLAN §2). |
| 3.6 | `llms.txt` accurate | Needs staging | Built from data; re-read after the staging seed and settings. |
| 3.7 | `robots.txt` allows search and AI crawlers in production, disallows everything elsewhere | Needs production | `SITE_INDEXABLE`; AI crawler list is OPEN-QUESTIONS 5.9. |

---

## 4. Accessibility

| # | Item | Status | Notes |
|---|---|---|---|
| 4.1 | Keyboard-only pass on the booking flow (/book → checkout → transfer page → account) | Needs staging | Visible focus, logical order, no traps, the Livewire steps announce changes. |
| 4.2 | Screen reader pass (NVDA or VoiceOver) on the same flow | Needs staging | |
| 4.3 | axe scan clean on public pages, /book, checkout and account | Needs staging | Run against built assets. |
| 4.4 | Lighthouse Accessibility ≥ 95 on public pages | Needs staging | Milestone 2 baseline was 100 locally. |

---

## 5. Performance and security on production

| # | Item | Status | Notes |
|---|---|---|---|
| 5.1 | Lighthouse ≥ 95 (Performance, Best Practices, SEO) on public pages, mobile | Needs production | Milestone 11 (performance pass) is in progress on its own branch; re-measure after it merges. |
| 5.2 | Core Web Vitals field data: LCP < 2.0 s, INP < 200 ms, CLS < 0.05 | Needs production | Field data takes about 28 days of traffic. |
| 5.3 | JS < 50 KB gzipped on marketing pages | Needs staging | Check the built bundle. |
| 5.4 | Security headers on production (CSP with nonces, HSTS preload, frame-ancestors, Referrer-Policy, Permissions-Policy) | Needs production | Covered by `tests/Feature/Security`; verify with securityheaders.com and the browser console (no CSP violations). |
| 5.5 | `APP_DEBUG=false`, no stack traces, admin path secret, MFA enforced | Needs production | `DEPLOY.md` §1. |
| 5.6 | Rate limits work behind Cloudflare (visitor IP, not Cloudflare's) | Needs staging | `TRUSTED_PROXIES`; `DEPLOY.md` §13 last line. |
| 5.7 | Backups run and a restore was tested | Needs staging | `DEPLOY.md` §11 and §13. No off-site copy yet (accepted risk, see below). |
| 5.8 | Uptime monitoring and ops alerts | Needs production | UptimeRobot on `/`, `/book`, `/up`. |

---

## 6. Stripe live

| # | Item | Status | Notes |
|---|---|---|---|
| 6.1 | Live keys in production `shared/.env` (never committed) | Needs production | `STRIPE_KEY`, `STRIPE_SECRET`. |
| 6.2 | Live webhook endpoint `https://ride-pakistan.com/webhooks/stripe` with the events from PLAN §3.1, signing secret in `STRIPE_WEBHOOK_SECRET` | Needs production | Events: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, `charge.refunded`. Cloudflare skip rule for the webhook (`DEPLOY.md` §9). |
| 6.3 | A real small live payment, then a refund from the order page | Needs production | Check: order paid, ticket email, refund recorded, booking cancelled. |
| 6.4 | Statement descriptor and receipts checked | Needs owner | OPEN-QUESTIONS 10.1, 10.2. |
| 6.5 | Refund policy published before going live | Needs owner | 1.4 / 10.3. |

---

## 7. Staff guide

| # | Item | Status | Notes |
|---|---|---|---|
| 7.1 | `docs/STAFF-GUIDE.md` written for non-technical staff | Done | See 2.9. |
| 7.2 | Team accounts created (`php artisan admin:create`), each with an authenticator app | Needs production | OPEN-QUESTIONS 6.5. |
| 7.3 | Walk the team through the guide on staging (a transfer approval, a refund, a run sheet check-in) | Needs staging | |

---

## Open questions that still need answers

From `docs/OPEN-QUESTIONS.md`: every item that is not decided. **Bold** items block launch.

**Launch blockers**

- **1.4 / 10.3 / 7.2: Refund policy text.** Stripe requires it before live card payments.
- **7.1: Privacy policy and terms.** Pages are unpublished outlines.
- **12.1: Privacy wording for cookies and ad measurement.** Needed before Meta Pixel / GA4 are switched on (the banner only links to a published privacy page).
- **6.5: Who gets admin, staff and trainer accounts.**
- **11.5: The exact map pin.** Confirmation and reminder emails leave the location out until it is set.

**Open decisions (site uses the bracketed default)**

- 1.1 Riders per slot [6]
- 1.2 Bank transfer hold [24 hours]
- 1.3 Cancellation and reschedule cutoff [48 hours]
- 1.5 Minimum rider age [not set]
- 3.2 Bad weather policy (affects the weather notice templates)
- 11.1 Arrival time in reminders [not said]
- 11.2 Google review link for thank-you emails [none]
- 12.3 Alert the team for each new enquiry by email or WhatsApp? [admins get an email; no WhatsApp]

**To confirm (shown on the site now)**

- 2.3 What riders wear and bring
- 3.7 "Follow your trainer's instructions" riding rule
- 4.2 Packages: "two a week" as guidance or a rule
- 4.3 Maximum group size for a private session
- 4.5 "Beginner to confident trail rider" copy
- 4.6 Featured product (8-class package)
- 4.7 Product names
- 5.1 Omar's biography
- 5.2 "Leads your session and stays with you throughout"
- 5.4 Public contact email (`hello@ridepakistan.co` vs `hello@ride-pakistan.com`)
- 5.5 Instagram profile and DM links
- 5.8 Expedition photos on lesson pages
- 5.9 AI crawlers allowed in robots.txt
- 6.1 Age groups; 6.2 experience levels; 6.3 booking horizon [8 weeks]; 6.4 staff permissions
- 8.1 Order record retention [6 years]; 8.2 deletion grace [30 days]; 8.3 unused credits on deletion [forfeited]; 8.4 marketing consent wording; 8.5 sign-in code limits; 8.6 email changes by contacting you
- 9.1 Booking until the slot starts; 9.4 trainer filter hidden; 9.5 who may give free bookings
- 10.1 Card statement descriptor; 10.2 Stripe receipt emails
- 11.3 Reminder and thank-you timing; 11.4 check-in opens 2 hours before
- 12.2 Cookie banner wording; 12.4 first-touch revenue credit

**Removed until confirmed (nothing shown)**

- 2.1 Helmets; 2.2 other kit; 2.4 water, sunscreen, layers
- 3.1 Arrival time; 3.3 minors and guardians; 3.4 photography; 3.5 transport; 3.6 beginner safety statement
- 4.4 What packages lead to
- 5.3 Reply time promise; 5.6 expedition details; 5.7 "most riders" claims

**Content still to write**

- 7.3 Guides (three outlines; `/guides` is noindex until there is content)

---

## Open risks

| Risk | Impact | Mitigation |
|---|---|---|
| No off-site backup copy (decided 2026-11-08) | Losing the server loses the backups too | Weekly manual download (`DEPLOY.md` §11) until an S3-compatible bucket is added. |
| No error tracking (Sentry/Flare deferred) | Errors are only in the daily log files | Check `storage/logs` after launch; ops alerts cover stuck queues and waiting transfers. |
| Resend free plan: 100 emails a day | A busy day (confirmations, reminders, leads, digests) could hit the limit and delay emails | Watch the Resend dashboard in the first weeks; upgrade if close. Failures alert admins. |
| Bank transfers within 12 hours of a session aren't offered | Last-minute bookers must pay by card | Lower `booking.transfer_min_notice_hours` once someone watches the queue on weekend mornings (OPEN-QUESTIONS 10.5). |
| Real file uploads aren't covered by browser tests (note A) | An upload problem on real phones would be missed by CI | Feature tests cover the upload endpoint; check a real phone upload on staging (1.14). |
| Milestone 11 (performance) still in progress | Performance targets not yet re-measured | Re-run 5.1 and 5.3 after it merges. |
| Several policies are placeholders | Customer disputes (refunds, weather, minors) without a written rule | Answer the blockers above before launch; the staff guide tells staff to ask the owner before promising refunds. |
