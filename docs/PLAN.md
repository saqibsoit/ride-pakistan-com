# Ride Pakistan: Lessons & Booking Platform (ride-pakistan.com)

Master plan. Every build prompt in `/prompts` refers back to this file. If a prompt and this plan disagree, this plan wins; update it first, then the code.

## 1. What we are building

A fast, secure, bilingual-ready website for Ride Pakistan’s weekend horse riding sessions in Islamabad, with:

- Public marketing pages that rank in Google and get cited by AI answer engines.
- A booking engine: weekend slots, capacity, single sessions, private sessions and multi-session packages with validity windows.
- Payments in PKR through Stripe Checkout (Destination Pakistan’s Stripe account), with bank transfer as a fallback that requires manual verification before a ticket is issued.
- Customer accounts: bookings, tickets (QR), package credits and payment history.
- Lead management: capture, source tracking (Instagram ads, UTM), pipeline and notes.
- An admin panel for bookings, slots, payments verification, leads, customers and content.

## 2. Business rules (source of truth)

| Product | Price (PKR) | Sessions | Validity |
|---|---|---|---|
| Single session | 10,000 per rider | 1 | Booked slot only |
| Private session | 15,000 per rider | 1 | Booked slot only |
| 8-class package (2 per week) | 60,000 | 8 | 6 weeks from confirmation |
| 16-class package (2 per week) | 100,000 | 16 | 10 weeks from confirmation |

- Sessions run every Saturday and Sunday. Current slots: 08:00–10:00 and 15:00–17:00, Asia/Karachi. Times change seasonally, so slots are generated from editable schedule rules, never hard-coded.
- Session content: bring horses in from the paddock, groom, tack up, ride (mostly trail; arena work on request), untack, massage and stretch.
- Trainer: Omar Saleem.
- Location shown publicly as “Islamabad” only. The exact map pin is sent after a booking is confirmed (stored as a setting, never on public pages).
- No experience needed.
- Package credits are only granted when the order is Paid. Validity starts at confirmation. Expired credits cannot be used (admin can extend manually with a logged reason).
- Private session: blocks the whole slot. Allowed only if the slot has no other confirmed or held bookings. A private hold takes the whole capacity, and its bookings (`is_private`) keep the slot blocked after payment even with fewer riders than the capacity. Riders: 1 up to the slot capacity, price per rider.
- Booking rules (milestone 5):
  - Bookable slots are rule and manual slots that are open and start after now plus `booking.min_notice_hours` [0]. Confirmed bookings + unexpired holds never exceed capacity; a rider is never in the same slot twice.
  - Single sessions cost the price per rider. A package costs its price once and belongs to the account: any of the customer's riders can use its credits, one credit per rider per session. Buying a package needs no date; an optional first session (one or more riders) is paid by credits from the new pack and must start before the pack would expire.
  - Card seat holds last `booking.card_hold_minutes` [30]; `holds:release` (every minute) expires unpaid card orders and frees their seats. Re-submitting the same booking while its order is held returns that order.
  - Credits book group sessions only (never private), from active packs with credits left that expire after the session starts, soonest-expiring first. `credits:expire` (daily) writes off unused credits on expired packs.
  - Customers cancel or move only their own credit bookings. Cancelling before the cutoff (`booking.cancellation_cutoff_hours`) returns the credit if the pack hasn't expired; after the cutoff the credit is used. Moving is allowed only before the cutoff, to a slot the pack still covers. Paid single and private bookings change only through an admin.
  - Admins can move or cancel any booking (reason required; cancellations may give the credit back), mark attended / no-show once the session has started (gate check-in below allows attended earlier), add manual bookings (a customer's credit, or no charge with a reason; never an order) and extend a pack (reason required, ledger "adjusted").
  - Overbooking is prevented by a row lock: every booking or hold write takes `SELECT … FOR UPDATE` on the slot row first, then reads the slot's bookings and holds with locking reads (plain reads could see an older REPEATABLE READ snapshot). Prices are always computed on the server from the product row (`PriceQuote`).
- Open decisions (store as settings, admin-editable, defaults in brackets):
  - Riders per slot [6]
  - Bank transfer hold window [24 hours], never past the session start; transfer not offered within [12 hours] of the session (`booking.transfer_min_notice_hours`; lower it, e.g. to 2, only once someone watches the verification queue at short notice on weekend mornings)
  - Cancellation / reschedule cutoff [48 hours before slot]
  - Refund policy text [TBD with Omar]
  - Minimum rider age [TBD with Omar]
- Every other business fact the site states but this plan does not confirm (equipment, arrival time, weather, minors, photography, transport, reply times, copy claims) is tracked in `docs/OPEN-QUESTIONS.md` with its placeholder and where it is used. Unconfirmed claims that could be unsafe if wrong are kept off the site until confirmed.

## 3. Payments

Currency is PKR everywhere. Store money as integers in minor units (paisa) in `amount_minor` columns. Never use floats for money.

### 3.1 Card (Stripe Checkout)
1. Customer chooses product + slot(s) and rider details. An `order` is created with status `pending_payment` and a unique human reference (`RP-` + 6 chars, no ambiguous characters). Slot seats are held (`booking_holds`, expires in 30 minutes for card).
2. Server creates a Stripe Checkout Session in PKR with `client_reference_id` = order id, `metadata` = order reference, and an idempotency key.
3. Fulfilment happens ONLY in the verified webhook (`checkout.session.completed`, plus `checkout.session.async_payment_succeeded/failed` and `checkout.session.expired`). The success page only shows status; it never fulfils.
4. On success: order `paid`, holds converted to confirmed bookings, credits granted for packages, ticket issued, emails sent. All in one DB transaction, idempotent on the Stripe event id.
5. On failure or abandonment: show the bank transfer option for the same order and reference.

Card payment rules (milestone 6):
- `stripe:check` (test keys only) confirms the account can create PKR Checkout Sessions. Stripe calls go through `App\Support\Stripe\StripeGateway`.
- "Pay by card" (`CreateStripeCheckout`) builds line items from the order's items, never from the request. Because Stripe keeps a session open for at least 30 minutes, it extends the order's and holds' expiry to now + max(`booking.card_hold_minutes`, 31 min); the session expires at exactly that time. An open session is reused; an expired one is replaced (idempotency key `checkout:{order}:{attempt}`).
- A failed (`async_payment_failed`) or expired session does **not** end the order: while its hold lasts it stays `pending_payment` with its seats, and the customer can try a card again or pay by bank transfer under the same reference. Only `holds:release` expires it. Before doing so it expires any open Stripe session, and it leaves the order alone if Stripe reports the session as complete (the webhook handles it).
- The webhook resolves the order from `client_reference_id` and checks `metadata.reference`, amount and currency. A mismatch is never fulfilled: it raises a critical log, an audit entry and a "Needs review" flag on the order. A payment that can no longer be fulfilled (order expired or cancelled, slot full) is refunded automatically and flagged.
- A paid order queues a "Payment received" receipt ("Ride Pakistan by Destination Pakistan") after commit (`OrderPaid` event). Card statements show the account's dashboard descriptor plus the suffix `STRIPE_STATEMENT_DESCRIPTOR_SUFFIX` [RIDE PAKISTAN].

### 3.2 Bank transfer (manual verification)
1. Customer selects bank transfer. Order status `awaiting_transfer`. Seats held for the bank transfer hold window.
2. Page shows Destination Pakistan bank details and the order reference to use as the payment reference.
3. Customer uploads a transfer screenshot (required) and optional transaction ID. Status `awaiting_verification`.
4. Admin verification queue: screenshot, amount, reference, customer, uploaded time. Actions:
   - Approve: same fulfilment path as Stripe (one shared `FulfilOrder` action).
   - Reject with reason: releases seats, emails customer.
5. A scheduled job expires unverified holds when the window passes and notifies the customer.

Bank transfer rules (milestone 7):
- Choosing bank transfer (`ChooseBankTransfer`, `POST /checkout/{reference}/transfer`) keeps the order and its reference, also after a failed or expired card attempt. Any open Stripe session is expired first; if Stripe reports it complete, the switch is refused (the webhook confirms the card payment). The order moves to `awaiting_transfer`, `payment_method = bank_transfer`.
- Deadline = the earlier of now + `booking.transfer_hold_hours` [24] and the first session's start. Bank transfer isn't offered when the session starts within `booking.transfer_min_notice_hours` [12]. A transfer made at 6 am for an 8 am session needs someone watching the queue; until that is arranged, 12 hours. A package with no first session gets the full window.
- Proof (`SubmitBankTransfer`): one JPG, PNG, WebP or PDF up to 5 MB. The type is sniffed from the content; images are decoded and re-encoded (EXIF and trailing data dropped, long edge at most 2400 px); PDFs must start with `%PDF-`. Stored on the private `transfers` disk (`storage/app/transfers`, no URL) under a random name. The customer can replace the file until it is reviewed. Uploads are limited to 10 an hour per customer.
- The first upload moves the order to `awaiting_verification` and holds the seats until the session starts: **an uploaded transfer never expires on its own**, so a slow review can't cancel a booking the customer has paid for. The queue marks orders past their deadline "Overdue". Only `awaiting_transfer` orders (no upload) expire at the deadline (`holds:release`), with an email.
- Emails: the customer ("we've received your transfer"), every admin user ("transfer to verify"), and the customer again on rejection (with the reason) or expiry. Approval sends the usual receipt (`OrderPaid`).
- Admin queue (Filament "Verify transfers", admins only; staff can't see proofs or decide): soonest deadline first. **Approve** (`ApproveBankTransfer`) records the verifier and time and a bank-transfer payment, then calls `FulfilOrder`; approving twice changes nothing, and if the seats are gone nothing is written. **Reject** (reason required) makes the order `rejected` and releases the seats. **Extend** (reason required) moves the deadline, never past the session start, and re-checks seats if the hold had lapsed. A dashboard widget shows how many are waiting and the oldest wait.
- Approving, rejecting and extending transfers, and opening proofs, are **admin only** (decided: it's money; fewer approvers means fewer mistakes and a cleaner audit trail). Staff never see transfer screenshots.
- Notifications (milestone 8): Laravel notifications through the `Notifier` service (logged in `notification_log`; automatic sends deduplicated). Email (brand Markdown theme, plain-text part) always; a WhatsApp channel exists behind `WHATSAPP_ENABLED` [false] and only logs until GT360 is connected. Sent to the account holder; anonymised addresses skipped.
  - Booking confirmed (card paid or transfer approved): tickets with QR, Pakistan times, and the exact map pin (`booking.map_pin_url`). **The pin appears only in confirmed-booking emails (confirmation and reminder), never on public pages.**
  - Transfer received / to verify (admins) / rejected / expired.
  - Reminder about 24 h before (`notifications:send reminders`, every 15 min): riding rules, arrival time if `booking.arrival_minutes` is set (empty until confirmed), pin, tickets. One per customer per session.
  - Slot cancelled by an admin: every confirmed booking is cancelled; credit bookings get the credit back whatever the cutoff; directly paid bookings are flagged "Needs review" for the admin to refund or move (no automatic refund); unpaid orders holding seats are cancelled (a late card payment is refunded by the webhook) and uploaded transfers are flagged. One email per customer.
  - Credits expiring within 7 days (daily, once per pack). Thank-you 1 to 24 h after a session the riders attended (hourly), with a Google review button only if `contact.google_review_url` is set.
  - Admins (not staff) can resend the emails that still match an order's or booking's state; each resend is logged.
- Retention: `transfers:prune-proofs` (daily) deletes **only the screenshot or PDF** `privacy.transfer_proof_retention_days` [90] days after review (approved or rejected). The transfer record (reference, transaction ID, who verified it and when, rejection reason), the payment record (amount) and the audit log are kept permanently for accounting. Proofs still waiting for review are never deleted.
- Proofs are served only by `GET /{admin-path}/transfers/{id}/proof`: a signed URL valid for 5 minutes, a signed-in admin, `nosniff`, `no-store` and a sandbox CSP. PDFs download. Each view is logged.

Bank details (settings, not hard-coded; defaults come from `BANK_*` in `.env`, never from code): Account name Destination Pakistan; Bank Standard Chartered Bank; IBAN PK91SCBL0000001702859701; SWIFT SCBLPKKX; Country Pakistan; Bank address Main Building, Floor 3, I.I. Chundrigar Road, Karachi 74200.

### 3.3 Order state machine
`pending_payment → paid`
`pending_payment → awaiting_transfer → awaiting_verification → paid | rejected`
`any unpaid → expired | cancelled`
`paid → refunded (partial | full)` (statuses `partially_refunded` and `refunded`; a partially refunded order can be refunded again)
"Any unpaid" means `pending_payment`, `awaiting_transfer` and `awaiting_verification`. `rejected`, `expired`, `cancelled` and `refunded` are final.
Every transition is written to an audit log with actor, timestamp and reason.

Refunds (milestone 6, card orders): admins refund from the order page (`RefundOrder`, Stripe API, reason required, never more than is left). A **full** refund cancels every upcoming booking the order paid for, directly or through its credit pack, and voids the pack's unused credits (pack `revoked`, ledger "adjusted"). A **partial** refund cancels only the bookings the admin picks and removes the number of unused credits the admin enters (0 or more); a cancelled booking's credit is not returned. `charge.refunded` records refunds not already recorded (made in the Stripe dashboard). These change the money and status only, and the order is flagged for review.

## 4. Tech stack

- **Framework:** Laravel 12 on PHP 8.3 (`config.platform.php` pinned to 8.3.0; CI runs 8.3). Decided at bootstrap (2026-11-07): Laravel 13 plus the latest Pest and Spatie majors need PHP 8.4, and responsecache 7.x (the last on PHP 8.3) doesn't support Laravel 13. When Hostinger moves to PHP 8.4, raise the pin and upgrade to Laravel 13, Pest 5 and the current Spatie majors in one milestone.
- **Database:** MySQL/MariaDB as provided by Hostinger shared hosting (see section 10). InnoDB, utf8mb4, strict mode.
- **Frontend:** Blade + Tailwind CSS + Alpine.js. Livewire only for interactive parts (booking calendar, checkout steps, account). No SPA.
- **Admin:** Filament (latest stable) at an unguessable path, with MFA.
- **Payments:** `stripe/stripe-php` directly (Checkout Sessions + webhooks). Not Cashier, since there are no subscriptions.
- **Media:** responsive AVIF/WebP generated at upload time; private disk for transfer screenshots.
- **Queues:** `database` driver, processed by cron (no Supervisor on shared hosting).
- **Cache/session:** `file` or `database` (no Redis on shared hosting); full-page response cache for public pages.
- **Email:** transactional provider via SMTP/API (Postmark, Resend or Amazon SES) with SPF, DKIM and DMARC on ride-pakistan.com.
- **Bot protection:** Cloudflare Turnstile on public forms + honeypot + rate limits.
- **CDN/WAF:** Cloudflare in front of Hostinger (proxied DNS, Full (strict) SSL).
- **Quality:** Pest, Larastan (level 8, reached at bootstrap; never lower it), Pint (with `declare_strict_types`), `composer audit`, `npm audit`, GitHub Actions CI.

## 5. Data model (initial)

Built in milestone 2 (ahead of milestone 3, because public pages need them): `settings`, `products`, `schedule_rules`, `leads`, `pages`, `faqs`, `posts`. Milestone 3 built the rest below. Statuses are PHP backed enums in `app/Enums`; money columns are `*_minor` integers; datetimes are UTC `dateTime` columns.

Roles (milestone 3): `customer` (site and own account only), `staff` (admin panel: read-only slots and bookings; check-in from milestone 8), `admin` (everything). Staff and admins must use app-based (TOTP) MFA with recovery codes; passwords are at least 12 characters with mixed case, numbers and symbols (plus a breached-password check in production). Team accounts are created with `php artisan admin:create`. Role changes go through `ChangeUserRole` (an admin cannot change their own role) and are logged.


- `users` (customers and staff; `role` enum: customer, staff, admin; phone E.164; marketing consent flag + timestamp of the last change; encrypted TOTP secret and recovery codes; `name` and `password` nullable for code-only customers; `session_version` for sign-out everywhere; soft deletes (`deleted_at` = deletion requested) and `anonymised_at`)
- `login_codes` (milestone 4: channel, destination, HMAC-SHA256 `code_hash`, attempts, expires_at, consumed_at, ip_address; pruned daily a day after expiry)
- `riders` (belongs to user; name, age bracket, experience level, notes, emergency contact; notes and emergency contact encrypted and redacted in the audit log)
- `products` (type: single, private, package; name; price_minor; sessions_count; validity_days; is_active; sort)
- `schedule_rules` (day_of_week, start_time, end_time, capacity, valid_from, valid_to, is_active)
- `slots` (schedule_rule_id nullable, source: rule, manual; starts_at, ends_at, capacity, status: open, closed, cancelled; cancel_reason, cancelled_at, cancelled_by; unique on starts_at). Rule slots are generated from schedule rules. Admins add manual slots from the Slots screen through `CreateManualSlot`: any future date and time, own capacity (1 to 50, default `booking.riders_per_slot`), never at a start time another slot already has; each one is logged. `GenerateSlots` (daily `slots:generate`, after every rule change, and on seed) fills the horizon set by `booking.slot_horizon_weeks` [8]. Only future open slots with no booking, hold or order line follow rule changes or are removed; slots in use, closed or cancelled slots, past slots and manual slots are never touched. If a rule produces a start time a manual slot already holds, the manual slot stays and no rule slot is created. Status changes go through `ChangeSlotStatus` (open ⇄ closed, open/closed → cancelled; cancelling needs a reason, is final and fires `SlotCancelled`).
- `orders` (reference unique, user_id, product_id, status, payment_method, amount_minor, amount_refunded_minor, currency, stripe_checkout_session_id, stripe_payment_intent_id, expires_at, paid_at, meta json). Status changes only through `OrderStateMachine` (§3.3; reasons required for rejected, cancelled, partially_refunded and refunded).
- `order_items` (order_id, slot_id nullable, rider_id, unit_price_minor; a package has one item for the pack plus a 0-priced item per first-session rider)
- `booking_holds` (slot_id, order_id, seats, is_private, expires_at)
- `bookings` (slot_id, order_id nullable, rider_id, user_id, credit_pack_id nullable, is_private, status: confirmed, cancelled, attended, no_show (changes through `ChangeBookingStatus`), ticket_code unique, 12 unambiguous characters; created only by `FulfilOrder`, `BookWithCredit` or an admin manual booking)
- `credit_packs` (user_id, order_id, credits_total, credits_used, valid_from, expires_at, status)
- `credit_ledger` (credit_pack_id, booking_id nullable, delta, reason: granted, used, returned, adjusted, expired; note, actor_id)
- `bank_transfers` (order_id, screenshot_path private, transaction_ref, submitted_at, verified_by, verified_at, status, rejection_reason)
- `payments` (order_id, provider, provider_id, amount_minor, status, raw json)
- `stripe_events` (event_id unique, type, processed_at) for idempotency
- `leads` (name, phone, email, interest, preferred_date, message, status: new, contacted, qualified, booked, lost; source, utm_* fields, fbclid, landing_url, referrer, assigned_to, user_id nullable)
- `lead_notes` (lead_id, author_id, body)
- `settings` (key/value, typed, cached; keys and types in `App\Enums\SettingKey`, defaults in `config/settings.php`)
- Caches: the full-page cache has its own store (`responsecache`, file). `ResponseCache::clear()` empties the whole store it uses, so it must never share one with rate limiters, settings or anything else (fixed in milestone 7).
- `pages` / `faqs` / `posts` (CMS content for SEO pages, guides and FAQ)
- `activity_log` (audit trail for all admin actions and order transitions)
- `notification_log` (milestone 8: user, type, subject, `dedupe_key` unique for automatic sends, `resent_by`, queued_at, sent_at)

Overbooking protection: seat checks and holds run inside a transaction with `SELECT ... FOR UPDATE` on the slot row. Confirmed + active held seats can never exceed capacity. A concurrency test proves this.

## 6. Pages and routes

Design sources: see `docs/reference/DESIGN-REFERENCES.md`. Brand look comes from our static page; page structure and booking UX come from the three inspiration screenshots.

Public (server-rendered, cached):
- `/` Home (lessons landing, same story and look as the static page, plus a "This weekend" strip showing upcoming slots with seats left)
- `/experiences` Catalog of all bookable products with filter chips (inspiration 03)
- `/sessions/{product}` Product detail page with sticky "From PKR X" box, key facts, timeline, what to bring, what’s included, good to know, and an inline booking widget with live seats left (inspiration 01)
- `/schedule` Weekly calendar of live slots (rule and manual) with seats left, filters All / Group / Private (`?type=`), click to book (inspiration 02). A trainer filter appears once slots have more than one trainer
- `/prices` Lesson options, pricing with packs and validity, riding rules and booking policies (inspiration 02)
- `/book` Booking flow (Livewire, not cached): product → slot → riders → review; `?product`, `?slot`, `?riders` start part-way, `?use=credits` books with package credits, `?resume=1` continues after a guest signs in. Product pages have a plain-form widget (next slots, seats left, rider count) that opens it pre-filled
- `/trainer/omar-saleem` Trainer profile (Person schema)
- `/faq`
- `/guides/{slug}` Content for SEO/AEO (e.g. “Horse riding lessons in Islamabad: a beginner’s guide”, “What to wear for your first riding lesson”)
- `/contact` (lead form)
- `/expeditions` (links to ridepakistan.co)
- `/privacy`, `/terms`, `/refund-policy` (required by Stripe)
- `/sitemap.xml`, `/robots.txt`, `/llms.txt`

Customer (auth, not cached, `Cache-Control: no-store, private`, noindex):
- `/login` (email → 6-digit one-time code at `/login/code`; phone/WhatsApp later behind the same `LoginCodeChannel` contract), `/login/restore` (keep an account scheduled for deletion), `POST /logout`
- `/account` dashboard (next session, credits left with expiry), `/account/bookings` (credit bookings: `/account/bookings/{ticket}/reschedule` and `/cancel`), `/account/tickets` and `/account/tickets/{code}`, `/account/credits`, `/account/orders` and `/account/orders/{reference}` (printable receipt), `/account/riders` (Livewire), `/account/profile`, `/account/privacy` (download my data, sign out everywhere, delete my account)

Payment:
- `/checkout/{reference}` order summary and seat hold (milestone 5); choose method (milestones 6–7): `POST /checkout/{reference}/card` (rate limited) hands over to Stripe Checkout, `/checkout/{reference}/transfer` bank details + upload (milestone 7), `/checkout/{reference}/status` (Livewire, polls every 3 s for up to 2 min; read-only)
- `POST /webhooks/stripe` (CSRF-exempt, signature-verified, rate limited)

Staff:
- `/{admin-path}` Filament: Dashboard, Slots & schedule rules, Orders, Bank transfer verification queue, Bookings & check-in, Customers & riders, Credit packs, Leads (kanban + list), Content, Settings, Audit log
- `/checkin/{ticket_code}` staff-only QR check-in page (mobile-first). Milestone 8 rules:
  - Who: staff and admins (gate `check-in`). Guests go to the admin panel login (MFA), never to customer codes; customers get 403; team accounts without MFA set up finish that first. Staff may only record attended / no-show here, nothing else beyond their read access.
  - When: attended from `booking.checkin_opens_minutes` [120] before the start until the session ends; no-show only between the start and the end; dates are Pakistan dates. Another day, a cancelled ticket, before opening or after the end: a clear warning and no button. A repeat tap says "already checked in" and changes nothing. Corrections afterwards are admin-only in the panel.
  - Tickets: the QR code (chillerlan/php-qrcode) holds only this URL. SVG on the printable account ticket page; PNG inline in emails and attached to the confirmation.

## 7. Security (non-negotiable)

Target OWASP ASVS Level 2 and OWASP Top 10 coverage.

- HTTPS everywhere, HSTS (preload-ready), secure + HttpOnly + SameSite=Lax cookies, session regeneration on login.
- Strict Content-Security-Policy with nonces (no `unsafe-inline` scripts), plus `X-Content-Type-Options`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`, `frame-ancestors 'none'` (except where Stripe requires).
- Admin: MFA required, strong passwords (HIBP check), login throttling, unguessable path, optional Cloudflare Access/IP allowlist.
- Authorization via Policies on every model; no mass assignment (`$fillable` only); Form Requests for all input.
- Rate limiting on login, OTP, lead form, checkout creation, uploads and webhooks.
- Uploads: private disk outside `public`, MIME + extension + size checks (≤ 5 MB, jpg/png/webp/pdf), re-encode images, strip EXIF, random filenames, served only via signed, short-lived admin URLs.
- Stripe webhook signature verification, idempotency on event id, amount and currency re-checked against the order before fulfilment.
- Secrets only in `.env` outside the web root; `APP_DEBUG=false` in production; no stack traces to users.
- PII minimisation; encrypted casts for sensitive fields (emergency contact, notes); data export and deletion on request.
- Customer sign-in (milestone 4): one-time email codes, 6 digits, 10-minute expiry, single use, stored as an HMAC keyed with the app key, dead after 5 wrong guesses; a new code replaces earlier ones. Requests limited to 3 per 10 minutes and 10 a day per email and 10 an hour per IP; submissions to 10 a minute per IP (`config/login.php`). Turnstile on the request form (fails closed in production without keys). Responses never reveal whether an account exists. The account is created on the first verified code. **Staff and admins never get a code** (it would skip the admin panel's MFA); they sign in at the admin path. Sessions are regenerated on sign-in; "sign out everywhere" bumps `users.session_version`, which ends every session (customer and admin panel) carrying the old one.
- Account deletion (milestone 4): blocked while the customer has upcoming confirmed bookings or unpaid open orders; unused credits are forfeited only with explicit consent; team accounts are removed by an admin. The account is soft-deleted and signed out. Inside the grace period (`privacy.deletion_grace_days` [30]) signing in again offers "Keep my account". After it, `accounts:anonymise` (daily) anonymises the account once its newest paid order (`paid_at`, refunded orders included) is older than the retention period (`privacy.order_retention_years` [6]); until then it stays locked. Anonymising clears the name, phone, consent and email (replaced by `deleted-{id}@anonymised.invalid`), deletes unused riders, strips booked riders to "Former rider", clears linked leads and login codes, and redacts audit-log values for the account and its riders. Orders, bookings and payments are kept.
- Full audit log for admin actions and all order/payment state changes.
- Dependencies: `composer audit` and `npm audit` in CI; Dependabot.
- Daily encrypted off-site backups (database + private files), 30-day retention, restore tested before launch.
- Error monitoring (Sentry or Flare) and uptime monitoring.

## 8. SEO, AEO and GEO

- Server-rendered semantic HTML, one `h1` per page, logical heading order, descriptive URLs.
- Per-page title, meta description, canonical, Open Graph and Twitter cards, managed from the CMS with sane defaults.
- JSON-LD structured data: `Organization` + `SportsActivityLocation`/`LocalBusiness` (area served: Islamabad, no street address until decided), `Person` (trainer), `Product`/`Offer` for each priced product in PKR, `FAQPage`, `BreadcrumbList`, `Article` for guides, `WebSite`.
- XML sitemap (auto-updated), `robots.txt`, `llms.txt` summarising the business, products, prices, schedule and booking URL in plain language.
- AEO/GEO content pattern: every key page opens with a direct, factual 2–3 sentence answer (what, where, when, price, who it’s for), followed by detail. Consistent entity facts (name, location, schedule, prices, trainer) across all pages, Google Business Profile and Instagram.
- Guides that answer real questions people ask (beginner lessons in Islamabad, what to wear, is horse riding safe for beginners, private vs group lessons).
- Internal linking between guides, prices and booking.
- Hreflang-ready i18n structure (English first, Urdu later).
- Allow reputable search and AI crawlers in `robots.txt` (decision recorded in settings: `seo.ai_crawlers`).
- Entity facts (schedule, prices, validity, location, trainer) are generated from data by `App\Support\BusinessFacts`, never typed into copy. FAQ answers use tokens (`{schedule}`, `{price:slug}`, `{per_session:slug}`, `{validity:slug}`, `{cutoff}`, `{location}`, `{trainer}`) so a price or time change updates every page, the JSON-LD and `llms.txt` at once.
- Indexing is controlled by `SITE_INDEXABLE` (defaults to true only when `APP_ENV=production`): otherwise every response carries `X-Robots-Tag: noindex, nofollow` and robots.txt disallows all.

## 9. Performance and accessibility targets

- Core Web Vitals (mobile, field data): LCP < 2.0 s, INP < 200 ms, CLS < 0.05. Lighthouse ≥ 95 on Performance, Accessibility, Best Practices and SEO for all public pages.
- Public HTML served from full-page cache; cache busted on content/price/schedule changes.
- Self-hosted, subset WOFF2 fonts with `font-display: swap` and preload of the display font. No Google Fonts requests.
- Responsive images (`srcset`, AVIF/WebP, explicit width/height, lazy below the fold, `fetchpriority="high"` on the hero).
- JS budget: < 50 KB gzipped on marketing pages; Livewire only loaded on interactive pages.
- `php artisan optimize` (config, route, view, event cache) in production; OPcache on.
- Cloudflare: HTTP/3, Brotli, edge caching of static assets with long-lived immutable headers.
- WCAG 2.2 AA: contrast, focus states, keyboard navigation, form labels and errors, reduced-motion support.
- Colour rules (decided in milestone 1, tokens in `resources/css/tokens.css`):
  - Brand palette: earth, earth-2, saddle, sand, sand-dim, line.
  - Status tokens: `error` #E8917A, `success` #A8C49A, `warning` #E2B75E, each ≥ 4.5:1 on earth and earth-2. Status is never shown by colour alone (icon + text).
  - `line-strong` (sand at 45%, 3.9:1) for borders that must meet 3:1 (form fields, chips). `line` is for decorative dividers only.
  - `saddle` is 3.1:1 on earth: only for large display numerals and decoration, never body text. Sand text on a saddle background (4.8:1) is fine ("Selling fast").
- Lighthouse is measured against built assets (`npm run build`, no Vite dev server, Herd scripts blocked). Milestone 2 baseline (mobile, local Herd over HTTPS via `herd secure`, median of 3): Performance `/` 95, `/prices` 100, `/faq` 100; Accessibility, Best Practices and SEO 100 on all three.
- Image variants: `php artisan images:responsive` generates AVIF (where GD supports it) and WebP at 480/768/1200/1800 into `_variants/` next to each source; uploads dispatch `ImageUploaded`. Variants are build output (gitignored) and must be generated on deploy.

## 10. Database decision: MySQL

MySQL (MariaDB on Hostinger) is the right choice:

- Hostinger shared hosting provides MySQL/MariaDB only; PostgreSQL would need a VPS or an external managed database (extra cost and network latency on every query).
- The workload is small and transactional (bookings, orders, leads). InnoDB row locking covers the overbooking case.
- Laravel’s query builder keeps the code portable; if the project moves to a VPS later, switching to PostgreSQL is a migration, not a rewrite.

Rules: InnoDB, utf8mb4_unicode_ci, strict SQL mode, foreign keys everywhere, indexes on every foreign key and status/date column used in queries, all multi-step writes in transactions.

## 11. Hosting and deployment (Hostinger shared)

- Plan must include SSH access. Point the domain’s document root to Laravel’s `public` directory (or symlink `public_html` to it). Nothing else is web-accessible.
- Deploy via GitHub Actions: run tests → build assets → `composer install --no-dev --optimize-autoloader` → rsync over SSH → `php artisan migrate --force` → `php artisan optimize` → clear response cache. Zero manual FTP.
- Cron (every minute): `php artisan schedule:run`. The scheduler runs `queue:work --stop-when-empty --max-time=50`, expires holds, generates future slots, sends reminders, runs backups and prunes logs.
- Environments: local (Laravel Herd or Sail), staging (subdomain, noindex, Stripe test mode), production.

## 12. Milestones

| # | Milestone | Prompt file |
|---|---|---|
| 0 | Project bootstrap, tooling, CI, agent rules | `prompts/00-bootstrap.md` |
| 1 | Design system and layout | `prompts/01-design-system.md` |
| 2 | Public pages, CMS content, SEO/AEO/GEO foundation | `prompts/02-public-pages-seo.md` |
| 3 | Data model, schedule rules, slots, admin panel base (products, settings, schedule_rules and leads already exist from milestone 2) | `prompts/03-data-model-admin.md` |
| 4 | Customer auth and accounts | `prompts/04-auth-accounts.md` |
| 5 | Booking engine, holds, packages and credits | `prompts/05-booking-engine.md` |
| 6 | Stripe Checkout and webhooks | `prompts/06-stripe.md` |
| 7 | Bank transfer and manual verification | `prompts/07-bank-transfer.md` |
| 8 | Tickets, check-in and notifications | `prompts/08-tickets-notifications.md` |
| 9 | Leads CRM and tracking | `prompts/09-leads-tracking.md` |
| 10 | Security hardening | `prompts/10-security.md` |
| 11 | Performance pass | `prompts/11-performance.md` |
| 12 | Deployment, backups and monitoring | `prompts/12-deploy.md` |
| 13 | QA and launch checklist | `prompts/13-launch.md` |

Each milestone ends with: all tests green, Larastan clean, Pint clean, a short CHANGELOG entry and a summary of anything left open.
