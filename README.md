# Ride Pakistan (ride-pakistan.com)

Lessons and booking platform for Ride Pakistan's weekend horse riding sessions in Islamabad.

- **Source of truth:** [`docs/PLAN.md`](docs/PLAN.md) (business rules, stack, standards)
- **Agent rules:** [`AGENTS.md`](AGENTS.md) (copied to `CLAUDE.md` and `.github/copilot-instructions.md`)
- **Milestone prompts:** [`prompts/`](prompts)

## Stack

Laravel 12 on PHP 8.3 (pinned to match Hostinger), MySQL/MariaDB, Blade + Tailwind CSS 4 + Alpine.js, Livewire 4, Filament 5 admin, Stripe Checkout (`stripe/stripe-php`), Pest 4, Larastan (level 8), Pint.

## Local setup

Requirements: PHP 8.3 (Laravel Herd ships it as `php83`), Composer 2, Node 22+, and MySQL 8 / MariaDB 10.6+ (or SQLite for quick local work).

```bash
git clone <repo> ride-pakistan-com && cd ride-pakistan-com
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

- `ADMIN_PATH`: the Filament admin URL path. Pick any value locally; use a long, random one in production. Production refuses to boot without it.
- Database: `.env.example` targets MySQL. Create the database with `utf8mb4` / `utf8mb4_unicode_ci`:
  ```sql
  CREATE DATABASE ride_pakistan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  ```
  For a quick start without MySQL, set `DB_CONNECTION=sqlite`, comment out `DB_DATABASE`, and run `touch database/database.sqlite`. Booking/locking behaviour must still be verified on MySQL; CI does that.

Then:

```bash
php artisan migrate
npm install
npm run dev          # or: npm run build
```

With Herd, the site is served at `http://ride-pakistan-com.test`; otherwise run `php artisan serve`.

- `/` shows the placeholder home page.
- `/{ADMIN_PATH}` shows the Filament login. Panel access is denied to every user until staff roles exist (milestone 3/4).

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

Tests run on in-memory SQLite locally (`phpunit.xml`); CI overrides `DB_CONNECTION=mysql` and runs them against MySQL 8.4.

## CI and dependencies

- `.github/workflows/ci.yml`: Pint, Larastan, Pest on MySQL, `composer audit`, `npm audit`, plus a production asset build.
- `.github/dependabot.yml`: weekly composer, npm and GitHub Actions updates. Composer respects the PHP 8.3 platform pin.

## Secrets

Never commit `.env`, keys or customer data. Stripe keys, bank details and prices live in `.env` or admin settings, never in code.
