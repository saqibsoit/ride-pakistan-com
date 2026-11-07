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
- Private session: blocks the whole slot. Allowed only if the slot has no other confirmed or held bookings.
- Open decisions (store as settings, admin-editable, defaults in brackets):
  - Riders per slot [6]
  - Bank transfer hold window [24 hours]
  - Cancellation / reschedule cutoff [48 hours before slot]
  - Refund policy text [TBD with Omar]
  - Minimum rider age [TBD with Omar]

## 3. Payments

Currency is PKR everywhere. Store money as integers in minor units (paisa) in `amount_minor` columns. Never use floats for money.

### 3.1 Card (Stripe Checkout)
1. Customer chooses product + slot(s) and rider details. An `order` is created with status `pending_payment` and a unique human reference (`RP-` + 6 chars, no ambiguous characters). Slot seats are held (`booking_holds`, expires in 30 minutes for card).
2. Server creates a Stripe Checkout Session in PKR with `client_reference_id` = order id, `metadata` = order reference, and an idempotency key.
3. Fulfilment happens ONLY in the verified webhook (`checkout.session.completed`, plus `checkout.session.async_payment_succeeded/failed` and `checkout.session.expired`). The success page only shows status; it never fulfils.
4. On success: order `paid`, holds converted to confirmed bookings, credits granted for packages, ticket issued, emails sent. All in one DB transaction, idempotent on the Stripe event id.
5. On failure or abandonment: show the bank transfer option for the same order and reference.

### 3.2 Bank transfer (manual verification)
1. Customer selects bank transfer. Order status `awaiting_transfer`. Seats held for the bank transfer hold window.
2. Page shows Destination Pakistan bank details and the order reference to use as the payment reference.
3. Customer uploads a transfer screenshot (required) and optional transaction ID. Status `awaiting_verification`.
4. Admin verification queue: screenshot, amount, reference, customer, uploaded time. Actions:
   - Approve: same fulfilment path as Stripe (one shared `FulfilOrder` action).
   - Reject with reason: releases seats, emails customer.
5. A scheduled job expires unverified holds when the window passes and notifies the customer.

Bank details (settings, not hard-coded; defaults come from `BANK_*` in `.env`, never from code): Account name Destination Pakistan; Bank Standard Chartered Bank; IBAN PK91SCBL0000001702859701; SWIFT SCBLPKKX; Country Pakistan; Bank address Main Building, Floor 3, I.I. Chundrigar Road, Karachi 74200.

### 3.3 Order state machine
`pending_payment → paid`
`pending_payment → awaiting_transfer → awaiting_verification → paid | rejected`
`any unpaid → expired | cancelled`
`paid → refunded (partial | full)`
Every transition is written to an audit log with actor, timestamp and reason.

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

Built in milestone 2 (ahead of milestone 3, because public pages need them): `settings`, `products`, `schedule_rules`, `leads`, `pages`, `faqs`, `posts`. Milestone 3 builds the rest and must not recreate these.


- `users` (customers and staff; `role` enum: customer, staff, admin; phone E.164; marketing consent flag + timestamp)
- `riders` (belongs to user; name, age bracket, experience level, notes, emergency contact)
- `products` (type: single, private, package; name; price_minor; sessions_count; validity_days; is_active; sort)
- `schedule_rules` (day_of_week, start_time, end_time, capacity, valid_from, valid_to, is_active)
- `slots` (starts_at, ends_at, capacity, status: open, closed, cancelled; generated from rules; unique on starts_at)
- `orders` (reference unique, user_id, product_id, status, payment_method, amount_minor, currency, stripe_checkout_session_id, stripe_payment_intent_id, expires_at, paid_at, meta json)
- `order_items` (order_id, slot_id nullable, rider_id, unit_price_minor)
- `booking_holds` (slot_id, order_id, seats, expires_at)
- `bookings` (slot_id, order_id, rider_id, user_id, status: confirmed, cancelled, attended, no_show, source credit_id nullable, ticket_code unique)
- `credit_packs` (user_id, order_id, credits_total, credits_used, valid_from, expires_at, status)
- `credit_ledger` (credit_pack_id, booking_id nullable, delta, reason, actor_id)
- `bank_transfers` (order_id, screenshot_path private, transaction_ref, submitted_at, verified_by, verified_at, status, rejection_reason)
- `payments` (order_id, provider, provider_id, amount_minor, status, raw json)
- `stripe_events` (event_id unique, type, processed_at) for idempotency
- `leads` (name, phone, email, interest, preferred_date, message, status: new, contacted, qualified, booked, lost; source, utm_* fields, fbclid, landing_url, referrer, assigned_to, user_id nullable)
- `lead_notes` (lead_id, author_id, body)
- `settings` (key/value, typed, cached; keys and types in `App\Enums\SettingKey`, defaults in `config/settings.php`)
- `pages` / `faqs` / `posts` (CMS content for SEO pages, guides and FAQ)
- `activity_log` (audit trail for all admin actions and order transitions)

Overbooking protection: seat checks and holds run inside a transaction with `SELECT ... FOR UPDATE` on the slot row. Confirmed + active held seats can never exceed capacity. A concurrency test proves this.

## 6. Pages and routes

Design sources: see `docs/reference/DESIGN-REFERENCES.md`. Brand look comes from our static page; page structure and booking UX come from the three inspiration screenshots.

Public (server-rendered, cached):
- `/` Home (lessons landing, same story and look as the static page, plus a "This weekend" strip showing upcoming slots with seats left)
- `/experiences` Catalog of all bookable products with filter chips (inspiration 03)
- `/sessions/{product}` Product detail page with sticky "From PKR X" box, key facts, timeline, what to bring, what’s included, good to know, and an inline booking widget with live seats left (inspiration 01)
- `/schedule` Weekly calendar of slots with filters, seats left per slot, click to book (inspiration 02)
- `/prices` Lesson options, pricing with packs and validity, riding rules and booking policies (inspiration 02)
- `/book` Booking flow (Livewire), also embedded as a widget on product pages
- `/trainer/omar-saleem` Trainer profile (Person schema)
- `/faq`
- `/guides/{slug}` Content for SEO/AEO (e.g. “Horse riding lessons in Islamabad: a beginner’s guide”, “What to wear for your first riding lesson”)
- `/contact` (lead form)
- `/expeditions` (links to ridepakistan.co)
- `/privacy`, `/terms`, `/refund-policy` (required by Stripe)
- `/sitemap.xml`, `/robots.txt`, `/llms.txt`

Customer (auth, not cached):
- `/login` (email one-time code; phone/WhatsApp OTP later)
- `/account` dashboard, `/account/bookings`, `/account/tickets/{code}`, `/account/credits`, `/account/orders/{reference}`

Payment:
- `/checkout/{reference}` choose method, `/checkout/{reference}/transfer` bank details + upload, `/checkout/{reference}/status`
- `POST /webhooks/stripe` (CSRF-exempt, signature-verified, rate limited)

Staff:
- `/{admin-path}` Filament: Dashboard, Slots & schedule rules, Orders, Bank transfer verification queue, Bookings & check-in, Customers & riders, Credit packs, Leads (kanban + list), Content, Settings, Audit log
- `/checkin/{ticket_code}` staff-only QR check-in page (mobile-first)

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
- Lighthouse is measured against built assets (`npm run build`, no Vite dev server, Herd scripts blocked). Milestone 2 baseline (mobile, local HTTP, median of 3): `/` 99, `/prices` 100, `/faq` 100 Performance; Accessibility and SEO 100; Best Practices 79 locally only because Herd serves HTTP (the two HTTPS audits), expected 100 behind Cloudflare.
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
