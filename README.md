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

- Public pages: `/`, `/experiences`, `/sessions/{slug}`, `/schedule`, `/prices`, `/faq`, `/guides`, `/trainer/omar-saleem`, `/expeditions`, `/contact`, `/book` (holding page until milestone 5). Legal pages (`/privacy`, `/terms`, `/refund-policy`) are 404 until published in the admin.
- `/sitemap.xml`, `/robots.txt` and `/llms.txt` are generated from content. Outside production (`SITE_INDEXABLE` false) every page is `noindex` and robots.txt disallows all.
- `/styleguide` (local environment only) shows every design token and Blade component.
- `/{ADMIN_PATH}` shows the Filament login. Content resources and the Settings page exist, but panel access is denied to every user until staff roles exist (milestone 3).

Settings (contact links, booking rules, policies, bank details) are edited in the admin; defaults live in `config/settings.php`. Bank details have no defaults in code: set the `BANK_*` values in `.env` (see `.env.example`).

Public pages are full-page cached (spatie/laravel-responsecache). Saving content, products, schedule rules or settings clears the cache and queues a sitemap rebuild; after editing the database by hand, run `php artisan responsecache:clear`.

Measure Lighthouse over HTTPS against built assets (`npm run build`, dev server stopped, Herd Studio scripts blocked): the Vite dev server and Herd Studio skew the results. The Vite dev server restarts when `.env` changes and rewrites `public/hot`.

Queue: `database` driver. Cache and sessions: `file` (shared hosting has no Redis). Locally, run `php artisan queue:work` when you need jobs processed.

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
