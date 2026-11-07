# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
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
- Prices follow PLAN §2 (8-class PKR 60,000, 16-class PKR 100,000), not the reference page's older rows. Bank details no longer appear on public pages; they move to the checkout transfer page (milestone 7).
- Test runs use a 512 MB memory limit (`phpunit.xml`) for the larger suite.
- Stack pinned to PHP 8.3 + Laravel 12 instead of Laravel 13 (see `docs/PLAN.md` §4).
- Test suite runs on MariaDB (`ride_pakistan_testing`) instead of SQLite, locally and in CI (MariaDB 11.8 service). `TestCase` refuses to boot unless the database name ends in `_testing`; Feature tests use `RefreshDatabase`.

### Removed
- `ride-pakistan-com-plan.zip` and the duplicate root `DESIGN-REFERENCES.md`.
