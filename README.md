# Ride Pakistan (ride-pakistan.com)

Lessons and booking platform for Ride Pakistan's weekend horse riding sessions in Islamabad.

- **Source of truth:** [`docs/PLAN.md`](docs/PLAN.md) (business rules, stack, standards)
- **Agent rules:** [`AGENTS.md`](AGENTS.md) (copied to `CLAUDE.md` and `.github/copilot-instructions.md`)
- **Milestone prompts:** [`prompts/`](prompts)

## Stack

Laravel 12 on PHP 8.3 (pinned to match Hostinger), MySQL/MariaDB, Blade + Tailwind CSS 4 + Alpine.js, Livewire 4, Filament 5 admin, Stripe Checkout (`stripe/stripe-php`), Pest 4, Larastan (level 8), Pint.

## Local setup

Requirements: PHP 8.3 (Laravel Herd ships it as `php83`), Composer 2, Node 22+, and MariaDB 11.8+ (Hostinger runs MariaDB; MySQL 8 also works with `DB_CONNECTION=mysql`).

```bash
git clone <repo> ride-pakistan-com && cd ride-pakistan-com
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

- `ADMIN_PATH`: the Filament admin URL path. Pick any value locally; use a long, random one in production. Production refuses to boot without it.
- Database: set `DB_HOST`, `DB_PORT`, `DB_USERNAME` and `DB_PASSWORD` for your local MariaDB. Development uses `DB_DATABASE=ride_pakistan`. Create both databases (development and tests) with `utf8mb4` / `utf8mb4_unicode_ci`:
  ```sql
  CREATE DATABASE ride_pakistan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE DATABASE ride_pakistan_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  ```
  If your DB user isn't root, grant it all privileges on both databases.

Then:

```bash
php artisan migrate --seed   # products, schedule rules, pages, FAQs, draft guides
npm install
npm run dev          # or: npm run build
php artisan images:responsive   # AVIF/WebP variants of public/images (gitignored)
```

With Herd, run `herd secure ride-pakistan-com` once and the site is served at `https://ride-pakistan-com.test` (HTTP redirects to HTTPS); otherwise run `php artisan serve`.

- Public pages: `/`, `/experiences`, `/sessions/{slug}`, `/schedule`, `/prices`, `/faq`, `/guides`, `/trainer/omar-saleem`, `/expeditions`, `/contact`, `/book` (booking flow; card payment at `/checkout/{reference}`, see Payments below). Legal pages (`/privacy`, `/terms`, `/refund-policy`) are 404 until published in the admin.
- `/sitemap.xml`, `/robots.txt` and `/llms.txt` are generated from content. Outside production (`SITE_INDEXABLE` false) every page is `noindex` and robots.txt disallows all.
- `/styleguide` (local environment only) shows every design token and Blade component.
- `/{ADMIN_PATH}` shows the Filament login. Create a team account with `php artisan admin:create` (admin or staff); on first sign-in it must set up an authenticator app (MFA is required). Slots are generated from the schedule rules by `php artisan slots:generate` (also run daily by the scheduler and after every rule change).

Settings (contact links, booking rules, policies, bank details) are edited in the admin; defaults live in `config/settings.php`. Bank details have no defaults in code: set the `BANK_*` values in `.env` (see `.env.example`).

Public pages are full-page cached (spatie/laravel-responsecache). Saving content, products, schedule rules or settings clears the cache and queues a sitemap rebuild; after editing the database by hand, run `php artisan responsecache:clear`.

Measure Lighthouse over HTTPS against built assets (`npm run build`, dev server stopped, Herd Studio scripts blocked): the Vite dev server and Herd Studio skew the results. The Vite dev server restarts when `.env` changes and rewrites `public/hot`.

Queue: `database` driver. Cache and sessions: `file` (shared hosting has no Redis). Locally, run `php artisan queue:work` when you need jobs processed.

## Payments (Stripe)

Card payment uses Stripe Checkout in PKR on Destination Pakistan's Stripe account. Orders are fulfilled **only** by the verified webhook (`POST /webhooks/stripe` → `HandleStripeEvent` → `FulfilOrder`). The status page after Stripe only shows what the webhook recorded.

### Setup

1. Put the **test** keys in `.env`: `STRIPE_KEY=pk_test_…`, `STRIPE_SECRET=sk_test_…`. Optional: `STRIPE_STATEMENT_DESCRIPTOR_SUFFIX` (default `RIDE PAKISTAN`).
2. Check that the account can take PKR:
   ```bash
   php artisan config:clear
   php artisan stripe:check   # creates a PKR 10,000 test session, prints it, expires it
   ```
   It refuses to run with live keys.
3. Install the [Stripe CLI](https://docs.stripe.com/stripe-cli) (`scoop install stripe` on Windows, `brew install stripe/stripe-cli/stripe` on macOS) and sign in with `stripe login`.
4. Forward webhooks to the local site, and keep this running while you test:
   ```bash
   stripe listen --forward-to https://ride-pakistan-com.test/webhooks/stripe
   ```
   It prints a signing secret (`whsec_…`). Put it in `.env` as `STRIPE_WEBHOOK_SECRET`, then run `php artisan config:clear`. The secret stays the same across `stripe listen` runs for the same CLI login.
5. Run a queue worker so receipt emails go out (`php artisan queue:work`). With `MAIL_MAILER=log` they land in `storage/logs/laravel.log`.

The scheduler (`php artisan schedule:work` locally) runs `holds:release` every minute. When a card order's hold runs out, it expires the open Stripe session first, then the order.

### Manual test

1. Book a session at `/book` and continue to `/checkout/{reference}`. Click **Pay by card**.
2. On Stripe's page, pay with `4242 4242 4242 4242` (any future expiry, any CVC). `stripe listen` shows `checkout.session.completed` → `200`. The status page switches to "You're booked". The booking and ticket appear under `/account/bookings`, and the receipt is queued.
3. Declined card: start again and use `4000 0000 0000 0002`. Stripe shows the decline on its own page. Go back: the order stays open with its seats held, and the checkout page offers card again or bank transfer under the same reference.
4. Abandoned session: click "back" on Stripe's page. The status page says nothing was charged. Expire the session with `stripe checkout sessions expire <cs_test_…>`: `checkout.session.expired` arrives, the order stays open, and "Try card again" appears.
5. Duplicate delivery: `stripe events resend <evt_…>`. The response is `{"duplicate": true}`, with no second booking.
6. Refund: in the admin, open the order (Orders → View) → **Refund** (full or partial, reason required). `charge.refunded` arrives and is recognised as already recorded. A refund made in the Stripe dashboard instead is recorded and the order is flagged "Needs review".
7. Bad signature: `curl -X POST https://ride-pakistan-com.test/webhooks/stripe -d '{}'` returns `400`.

`stripe trigger checkout.session.completed` sends a session that belongs to no order: it is logged and acknowledged, and nothing else happens. Use a real booking for end-to-end tests.

Tests never call Stripe: `tests/TestCase.php` binds `Tests\Support\FakeStripeGateway`, which still verifies webhook signatures with the real stripe-php code.

In production, add the endpoint `https://ride-pakistan.com/webhooks/stripe` in the Stripe dashboard. Subscribe it to `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired` and `charge.refunded`, and use its signing secret as `STRIPE_WEBHOOK_SECRET`.

## Quality checks

Run before every commit (CI runs the same):

```bash
php artisan test                 # Pest
vendor/bin/phpstan analyse       # Larastan, level 8
vendor/bin/pint --test           # code style + declare(strict_types=1)
composer audit
npm audit --audit-level=high
```

`vendor/bin/pint` (without `--test`) fixes style and adds missing `declare(strict_types=1);`.

### Running the tests locally

Tests always run on MariaDB, in the `ride_pakistan_testing` database:

1. Create `ride_pakistan_testing` (see the `CREATE DATABASE` statements above).
2. Make sure `.env` has working `DB_HOST`, `DB_PORT`, `DB_USERNAME` and `DB_PASSWORD`. Tests reuse these credentials.
3. Run `php artisan test`.

`phpunit.xml` forces `DB_CONNECTION=mariadb` and `DB_DATABASE=ride_pakistan_testing`, so the `DB_DATABASE` in `.env` (your development database) is ignored during tests. As a second safety net, `tests/TestCase.php` aborts the run before any migration if the database name doesn't end with `_testing` (for example, when a cached config points at `ride_pakistan`; run `php artisan config:clear`). Feature tests use `RefreshDatabase`: the schema is migrated once per run and each test runs inside a rolled-back transaction.

CI runs the same suite against a MariaDB 11.8 service container.

## CI and dependencies

- `.github/workflows/ci.yml`: Pint, Larastan, Pest on MariaDB 11.8, `composer audit`, `npm audit`, plus a production asset build.
- `.github/dependabot.yml`: weekly composer, npm and GitHub Actions updates. Composer respects the PHP 8.3 platform pin.

## Secrets

Never commit `.env`, keys or customer data. Stripe keys, bank details and prices live in `.env` or admin settings, never in code.
