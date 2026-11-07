# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
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
- Stack pinned to PHP 8.3 + Laravel 12 instead of Laravel 13 (see `docs/PLAN.md` §4).
- Test suite runs on MariaDB (`ride_pakistan_testing`) instead of SQLite, locally and in CI (MariaDB 11.8 service). `TestCase` refuses to boot unless the database name ends in `_testing`; Feature tests use `RefreshDatabase`.

### Removed
- `ride-pakistan-com-plan.zip` and the duplicate root `DESIGN-REFERENCES.md`.
