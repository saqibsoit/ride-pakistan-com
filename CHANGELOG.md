# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- Milestone 4 customer sign-in and accounts:
  - Passwordless sign-in at `/login`: email → 6-digit one-time code (10-minute expiry, single use, HMAC-SHA256 at rest, dead after 5 wrong guesses, a new code replaces older ones), rate limited per email and per IP, Cloudflare Turnstile on the request form (`TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY`; fails closed in production without keys). The account is created on the first verified code. Responses never reveal whether an account exists. Staff and admins never receive a code, so the admin panel's MFA can't be bypassed. Codes go through a `LoginCodeChannel` contract (email today) so a phone or WhatsApp channel can be added later. Expired codes are pruned daily.
  - Account area (`/account`, never cached, noindex): dashboard with the next session and credits left with expiry, upcoming and past bookings, tickets (code now, QR in milestone 8), credits with history, orders with status and a printable receipt, riders (Livewire: add, edit, remove; emergency contact and notes encrypted), profile (name, phone stored as E.164 for Pakistani and international numbers via `propaganistas/laravel-phone`, marketing consent unticked by default and timestamped on every change). "Account" link in the site header.
  - Privacy: "Download my data" (JSON export of profile, riders, orders, payments, transfers, bookings, credits and enquiries; no secrets, screenshot paths or raw provider payloads; logged, 5 an hour) and "Delete my account" (blocked by upcoming bookings or unpaid orders, explicit consent to forfeit credits, soft delete and sign-out; signing in again within `privacy.deletion_grace_days` [30] offers to keep the account; `accounts:anonymise` runs daily and anonymises once the grace period and `privacy.order_retention_years` [6] after the last paid order have passed).
  - Session security: session regenerated on sign-in and sign-out; "Sign out everywhere" via `users.session_version` (works with file sessions, also ends old admin panel sessions); session cookies Secure by default in production, HttpOnly, SameSite=Lax.
  - Admin: deleted accounts filter and status column on Customers & riders; new "Privacy and data retention" settings.
  - `config/csp.php`: register of third-party origins (Turnstile) for the milestone 10 Content-Security-Policy.
  - Pest tests: code expiry (boundary), reuse, five-guess lockout, replacement, team accounts refused, rate limits per email, per IP and on submissions, Turnstile pass, fail, missing token, outage and production without keys, end-to-end sign-in with session regeneration and intended redirect, restore flow, account pages, cross-customer access (404s, Livewire actions), profile and phone normalisation, consent timestamps, export contents, deletion guards, anonymisation (grace and retention edges, refunded orders, audit-log redaction, freed email), sign out everywhere, policies and cookie config.
- Milestone 3 data model, schedule, slots and admin base:
  - Roles (`customer`, `staff`, `admin`) on users, with phone (E.164) and marketing consent. The `access-admin` gate now means admin; staff enter the panel but only read slots and bookings.
  - Admin auth: required app (TOTP) MFA with recovery codes for every panel user, strong password defaults (12+ characters, mixed case, numbers, symbols; breached-password check in production), a per-email sign-in limit on top of Filament's per-IP limit, a profile page, and `php artisan admin:create`.
  - Migrations, models, enums, factories and policies for riders, slots, orders, order items, booking holds, bookings, credit packs, credit ledger, bank transfers, payments, Stripe events and lead notes (existing milestone 2 tables extended, not recreated). Rider emergency contacts and notes are encrypted; payment records are read-only in the admin; customers can see their own orders, bookings and credit packs.
  - `GenerateSlots` and `slots:generate` (daily at 02:30, after every schedule rule change, and on seed): idempotent, DST-safe (local wall-clock to UTC per date), and only future open slots with nothing attached follow rule changes. New setting `booking.slot_horizon_weeks` [8].
  - Manual slots: a `source` column on slots (`rule` or `manual`, existing slots backfilled as `rule`). Admins add a one-off slot on any future date and time with its own capacity from the Slots screen (`CreateManualSlot`, logged); start times stay unique. `GenerateSlots` never changes or removes manual slots, and a manual slot wins over a rule that produces the same start time. Source shows as a column, filter and detail field.
  - `OrderStateMachine`: the single place order status changes, validating PLAN §3.3 transitions under a row lock, requiring reasons for reject, cancel and refunds, and writing the audit log. `ChangeSlotStatus` for close, reopen and cancel (reason required, `SlotCancelled` event for milestone 8). `GenerateOrderReference` (`RP-` + 6 unambiguous characters).
  - Filament: Slots (list with booked, held and free seats, week view, detail with bookings, close, reopen and cancel actions, generate on demand), Customers & riders (role changes through `ChangeUserRole`, logged), Orders (read-only with status history), Activity log viewer, and navigation groups. Personal data (emails, phones, rider notes, emergency contacts) is redacted in the audit log.
  - Pest tests: slot generation (idempotency, DST in Asia/Karachi 2008 and Europe/London, rule changes never touching slots in use), state machine (every valid and invalid transition), slot status changes, policies per role, admin auth (MFA redirect, throttling, passwords) and every admin page.
- `docs/OPEN-QUESTIONS.md`: every business fact the site uses that PLAN does not confirm, with its placeholder and where it appears.
- Milestone 2 public pages, CMS content and SEO/AEO/GEO:
  - Data models (pulled forward from milestone 3): `settings`, `products` (PLAN §5 columns plus content fields), `schedule_rules`, `leads`, and CMS `pages`, `faqs` and `posts`, with factories, admin-only policies (behind an `access-admin` gate, denied until roles exist) and content audit logging.
  - Typed, cached settings (`App\Enums\SettingKey`, `App\Support\Settings`, `UpdateSettings` action); defaults in `config/settings.php`, bank details from `BANK_*` env values. `config/booking.php` removed; contact links moved from `config/site.php` to settings.
  - Filament resources for pages (block builder: text, image, quote, callout, call to action), FAQs, products (prices edited in rupees, stored in paisa), guides and schedule rules, plus a Settings page.
  - Public pages: home (reference story plus "This weekend"), `/experiences` (filter chips), `/sessions/{product}` (inspiration 01 layout, booking widget placeholder), `/schedule` (week calendar from schedule rules), `/prices`, `/faq`, `/guides`, `/guides/{slug}`, `/trainer/omar-saleem`, `/expeditions`, legal pages, `/contact` and a `/book` holding page. Every key page opens with an answer block generated from live data (`App\Support\BusinessFacts`).
  - SEO service (`App\Support\Seo`): title template, description, canonical, robots, Open Graph and Twitter cards with CMS overrides and fallbacks. JSON-LD `@graph` built with spatie/schema-org: Organization, WebSite, SportsActivityLocation (Islamabad, opening hours from schedule rules), Person, Product + Offer (PKR), FAQPage, BreadcrumbList and Article.
  - `/sitemap.xml` (regenerated on content change and nightly; `sitemap:generate`), environment-aware `/robots.txt` and `X-Robots-Tag`, and `/llms.txt` generated from settings, products and schedule.
  - Contact form storing leads with UTM, click-ID, landing and referrer attribution (`CaptureLead`), honeypot and per-IP rate limit.
  - Full-page response cache on public pages, cleared on any content, product, setting or schedule change.
  - Seeders for the four PLAN products, schedule rules, pages (trainer and expeditions copy from the reference page; legal pages as unpublished outlines), FAQs and three draft guides (outlines only).
  - Uploaded CMS images go through the responsive variant pipeline.
  - Pest tests for every public page (one h1, canonical, meta, JSON-LD types), unique titles and descriptions, sitemap, robots per environment, llms.txt, contact form, response cache invalidation, settings, facts and the admin resources.
  - `spatie/schema-org` ^4.
- Milestone 1 design system:
  - Self-hosted variable WOFF2 fonts (Big Shoulders Display, Instrument Sans; latin subset, OFL) with the display font preloaded and metric-matched fallbacks for near-zero CLS. Logo, brand photos, favicons, Apple touch icon and web manifest.
  - `resources/css/tokens.css`: Tailwind 4 `@theme` with brand colours, status colours (error, success, warning), `line-strong`, a fluid type scale, a hero size that scales with viewport width and height, spacing, radii and motion. Tailwind's default palette, type scale, radii and fonts are reset.
  - Base layout `<x-layouts.app>` (skip link, SEO `head` slot, sticky header with overlay mode and an Alpine mobile menu, footer with contact and legal links) and Blade components: button, badge, money, glyph, section heading, fact strip, step list, price row, FAQ item, breadcrumb, alert, form field, responsive image, hero, experience card, filter chips, seats left ("Selling fast" ≤ `booking.selling_fast_threshold`), slot card, sticky price box and mobile bottom bar.
  - Responsive image pipeline: `GenerateResponsiveVariants` action (AVIF + WebP at 480/768/1200/1800, no upscaling, JSON sidecar), `images:responsive` command, `ImageUploaded` event with a queued listener as the upload hook, and a `site` disk for `public/images`.
  - `App\Support\Money` for formatting minor units in views; `config/site.php`, `config/booking.php`, `config/images.php`.
  - Local-only `/styleguide`.
  - Pest tests for every component, the layout, the image pipeline and Money, plus a guard test that bans raw colours, arbitrary-value utilities and inline styles in views.
  - `intervention/image` ^3 (GD driver).
- Milestone 0 bootstrap: Laravel 12 (PHP 8.3 platform pin) with Pest 4, app name "Ride Pakistan", UTC storage timezone, `app.display_timezone` = `Asia/Karachi`, locale `en`.
- MySQL/MariaDB configuration (InnoDB, utf8mb4, utf8mb4_unicode_ci, strict mode); `database` queue, `file` cache and sessions.
- Tailwind CSS 4, Alpine.js 3, Vite 7; placeholder home page.
- Livewire 4 and a Filament 5 admin panel at `ADMIN_PATH` (required in production; deny-by-default panel access until roles exist).
- `stripe/stripe-php`, spatie/laravel-activitylog (config and migrations published), laravel-backup, laravel-responsecache and laravel-sitemap (configs published, not yet wired).
- Larastan at level 8 (the target level), Pint with `declare_strict_types` enforced on all PHP files.
- `app/Actions`, `app/Enums`, `app/Support`; agent rules copied to `CLAUDE.md` and `.github/copilot-instructions.md`.
- GitHub Actions CI (Pint, Larastan, Pest on MySQL 8.4, composer and npm audits) and Dependabot for composer, npm and Actions.
- README with local setup steps.

### Changed
- Removed unconfirmed claims that could be wrong or unsafe: helmet provision and requirements, arrival time, weather rescheduling, consent for minors, photography, transport, the "Is horse riding safe for beginners?" FAQ, reply-time promises, "Most popular", "multi-day" expeditions and package outcome claims. Riding rules and booking policies now default to confirmed or neutral wording only.
- Group size is derived from the riders-per-slot setting instead of a hard-coded "Up to 6 riders".
- Prices follow PLAN §2 (8-class PKR 60,000, 16-class PKR 100,000), not the reference page's older rows. Bank details no longer appear on public pages; they move to the checkout transfer page (milestone 7).
- Test runs use a 512 MB memory limit (`phpunit.xml`) for the larger suite.
- Stack pinned to PHP 8.3 + Laravel 12 instead of Laravel 13 (see `docs/PLAN.md` §4).
- Test suite runs on MariaDB (`ride_pakistan_testing`) instead of SQLite, locally and in CI (MariaDB 11.8 service). `TestCase` refuses to boot unless the database name ends in `_testing`; Feature tests use `RefreshDatabase`.

### Removed
- `ride-pakistan-com-plan.zip` and the duplicate root `DESIGN-REFERENCES.md`.
