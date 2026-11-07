# Prompt 00: Bootstrap the project

Read `AGENTS.md` and `docs/PLAN.md` fully before starting.

Set up a new Laravel project in this (empty) folder for ride-pakistan.com.

1. Install the latest stable Laravel with Pest. App name "Ride Pakistan", timezone UTC in config, display timezone `Asia/Karachi` as a config value, locale `en`.
2. Configure MySQL (utf8mb4, utf8mb4_unicode_ci, strict mode) in `.env.example`. Use `database` for queue, `file` for cache and sessions (shared hosting has no Redis).
3. Install and configure:
   - Tailwind CSS (latest), Alpine.js, Vite
   - Livewire (latest stable)
   - Filament (latest stable) with an admin panel at a path read from `ADMIN_PATH` env
   - Larastan (target level 8, start at the highest level that passes and record it), Laravel Pint, Pest + Pest Laravel plugin
   - `stripe/stripe-php`
   - spatie/laravel-activitylog, spatie/laravel-backup, spatie/laravel-responsecache, spatie/laravel-sitemap
4. Add `declare(strict_types=1);` to all app PHP files and enforce it in Pint config.
5. Create folders: `app/Actions`, `app/Enums`, `app/Support`, `docs/`. Copy `AGENTS.md` to `CLAUDE.md` and `.github/copilot-instructions.md` so every agent reads the same rules.
6. GitHub Actions CI (`.github/workflows/ci.yml`): PHP setup, composer install, npm ci + build, Pint test, Larastan, Pest (with MySQL service), `composer audit`, `npm audit --audit-level=high`.
7. Add `CHANGELOG.md`, a README with local setup steps, and `.editorconfig`.
8. Add Dependabot config for composer, npm and GitHub Actions.

Acceptance criteria:
- `php artisan test`, Larastan and Pint all pass in CI.
- Visiting `/` shows a placeholder page; `/{ADMIN_PATH}` shows the Filament login.
- No secrets in the repo.

Stop when done and report what was installed, versions, and anything you could not configure.
