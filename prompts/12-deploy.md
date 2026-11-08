# Prompt 12: Deployment, backups and monitoring (Hostinger shared)

Read `AGENTS.md` and `docs/PLAN.md` section 11.

1. Document Hostinger setup in `docs/DEPLOY.md`: PHP version and required extensions, SSH key setup, document root pointing to `public` (or `public_html` symlink), MySQL database and user with least privileges, cron entry `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`.
2. GitHub Actions deploy workflow for `staging` and `production` (manual approval for production): tests → build assets → `composer install --no-dev -o` → rsync release to a timestamped folder → shared `.env` and `storage` symlinks → `migrate --force` → `optimize` → switch `current` symlink → clear response cache → smoke test (curl `/` and `/up`). Rollback = switch symlink to previous release.
   - **Responsive images:** the build step must run `php artisan images:responsive` in CI (on a PHP with GD AVIF support) and ship the generated AVIF/WebP files in `public/images/_variants/` with the release. They are gitignored, so they only exist if CI makes them. Don't rely on generating them on the server, because Hostinger's PHP may not support AVIF. Fail the build if the command fails or no `.avif` files were produced.
3. Scheduler: queue worker (`queue:work --stop-when-empty --max-time=50`), release holds, expire transfers, generate slots, reminders, sitemap, backups, log pruning.
4. Backups: spatie/laravel-backup daily (DB + private uploads) encrypted, to off-site storage (S3-compatible or Google Drive), 30-day retention, weekly backup health check email. Document and perform a test restore on staging.
5. Monitoring: error tracking (Sentry or Flare), uptime checks on `/`, `/book` and `/up`, alert email when the queue has jobs older than 5 minutes or transfers wait more than 12 hours.
6. Cloudflare: proxied DNS, SSL Full (strict), HTTP/3, Brotli, WAF managed rules, bot fight mode tuned so it does not block Stripe webhooks (allow Stripe IPs / path rule for `/webhooks/stripe`).
7. Email DNS: SPF, DKIM, DMARC for ride-pakistan.com; verify deliverability with a mail tester.

Acceptance criteria:
- Push to `main` deploys to staging automatically; production deploy needs approval; rollback tested.
- Backup restore tested and documented.
