# Deployment, backups and monitoring

Milestone 12. How ride-pakistan.com runs on Hostinger shared hosting behind Cloudflare, how a release reaches staging and production, and what to do when something breaks. PLAN §11 has the rules; this file has the steps.

**Status (2026-11-08):** the workflow, scripts, scheduler, backups and alerts are built and tested in CI and locally. Hostinger, Cloudflare and mail DNS aren't set up yet. The [values I need from you](#1-values-i-need-from-you) come first; the [acceptance checklist](#13-acceptance-checklist) at the end lists what can only be proven on the real servers.

---

## 1. Values I need from you

Nothing below is in the repository. GitHub values go in **Settings → Environments → `staging` / `production`**: secrets are encrypted, variables are plain. Server values go in each environment's `shared/.env` (section 4).

### GitHub environment secrets (set in both `staging` and `production`)

| Secret | What it is | Where to find it |
|---|---|---|
| `SSH_HOST` | Server IP or host name | hPanel → Advanced → SSH Access |
| `SSH_PORT` | SSH port (Hostinger: `65002`) | same page; empty means 65002 |
| `SSH_USER` | SSH user, e.g. `u123456789` | same page |
| `SSH_PRIVATE_KEY` | Private half of the deploy key (section 3) | made by you, `ssh-keygen` |
| `SSH_KNOWN_HOSTS` | The server's host key line(s) | `ssh-keyscan -p 65002 <host>`, then check the fingerprint against hPanel |
| `SMOKE_TEST_TOKEN` | Optional. Random string sent as `X-Smoke-Test` so Cloudflare lets the deploy check through (section 9) | `openssl rand -hex 24` |

### GitHub environment variables (both environments)

| Variable | Staging example | Production example |
|---|---|---|
| `APP_URL` | `https://staging.ride-pakistan.com` | `https://ride-pakistan.com` |
| `DEPLOY_PATH` | `/home/u123456789/domains/ride-pakistan.com/app-staging` | `/home/u123456789/domains/ride-pakistan.com/app` |
| `PHP_BIN` | `/opt/alt/php83/usr/bin/php` (check with `ls /opt/alt/php8*/usr/bin/php`; empty means `php`) | same |

Use absolute paths, not `~`.

### GitHub settings

| Setting | Value |
|---|---|
| `production` environment → Required reviewers | You (and anyone else allowed to approve a production deploy) |
| `production` environment → Deployment branches | Selected branches: `main` |
| `staging` environment → Deployment branches | No restriction (lets you send a branch to staging by hand) |

### Server `.env` values (one `shared/.env` per environment)

| Key | Staging | Production |
|---|---|---|
| `APP_ENV` | `staging` | `production` |
| `APP_KEY` | new, `php artisan key:generate --show` | new, different from staging |
| `APP_DEBUG` | `false` | `false` |
| `APP_URL` | staging URL | `https://ride-pakistan.com` |
| `ADMIN_PATH` | random, long | random, long, different from staging |
| `SITE_INDEXABLE` | `false` | `true` (or leave empty) |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | staging database (section 4) | production database |
| `MAIL_MAILER` | `resend` | `resend` |
| `RESEND_API_KEY` | Resend API key, **server secret**: only in `shared/.env` (section 10) | same or a second key |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | `bookings@mail.ride-pakistan.com`, `Ride Pakistan` | same |
| `MAIL_REPLY_TO_ADDRESS`, `MAIL_REPLY_TO_NAME` | `hello@ride-pakistan.com`, `Ride Pakistan` | same |
| `STRIPE_KEY`, `STRIPE_SECRET` | **test** keys | live keys |
| `STRIPE_WEBHOOK_SECRET` | from the staging webhook endpoint | from the production endpoint |
| `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY` | Cloudflare Turnstile widget for both host names | same widget |
| `BANK_*`, `BOOKING_MAP_PIN_URL`, `WEATHER_LAT`, `WEATHER_LNG` | as in `.env.example` / PLAN §3.2 | same |
| `META_PIXEL_ID`, `META_CAPI_TOKEN`, `GA4_MEASUREMENT_ID` | empty | when tracking goes live |
| `BACKUP_ARCHIVE_PASSWORD` | random, 32+ characters | random, 32+, different |
| `BACKUP_NOTIFICATION_EMAIL` | your ops inbox | your ops inbox |
| `LOG_STACK`, `LOG_LEVEL`, `LOG_DAILY_DAYS` | `daily`, `info`, `14` | `daily`, `warning`, `14` |
| `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER` | `database`, `file`, `file` | same |
| `DB_DUMP_BINARY_PATH` | only if `which mysqldump` finds nothing | same |

### Other accounts and decisions

| Item | Needed for |
|---|---|
| Cloudflare account with the `ride-pakistan.com` zone, and the plan (Free or Pro) | section 9 |
| Resend: done. `mail.ride-pakistan.com` is verified; keep its DNS records when the nameservers move to Cloudflare | section 10 |
| UptimeRobot account and the alert email | section 8 |
| Who approves production deploys | GitHub settings above |

---

## 2. Hostinger: requirements

- **Plan with SSH access** (Premium, Business or Cloud). PLAN §11.
- **PHP 8.3** for the domain and the subdomain: hPanel → Advanced → PHP Configuration. The app is pinned to 8.3 (`composer.json` `config.platform.php`).
- **PHP extensions** (same page, "PHP extensions"): `mbstring`, `intl`, `pdo_mysql`, `bcmath`, `gd`, `zip`, `exif`, `fileinfo`, `openssl`, `curl`, `ctype`, `tokenizer`, `xml`, `dom`, `sodium`. Check over SSH with `$PHP_BIN -m`.
- **PHP options:** `memory_limit` 256M, `upload_max_filesize` and `post_max_size` 10M (proofs are at most 5 MB), `max_execution_time` 60, OPcache on.
- **Database:** MariaDB/MySQL, InnoDB, `utf8mb4_unicode_ci` (PLAN §10).
- **CLI tools used by the scripts:** `bash`, `rsync`, `mysqldump`, GNU `find`, `mv -T`, `readlink -f`. All are present on Hostinger's Linux servers; check `rsync --version` once.
- **GD and AVIF:** Hostinger's GD may not write AVIF. That doesn't matter for the shipped photos, whose variants are built in CI (section 6). Images uploaded later in the admin panel get WebP variants only if the server lacks AVIF (a warning is logged), and `<x-img>` falls back to WebP.

## 3. SSH deploy key

On your own machine:

```bash
ssh-keygen -t ed25519 -C "github-deploy ride-pakistan" -f ride-pakistan-deploy -N ""
```

1. hPanel → Advanced → SSH Access → SSH keys → add `ride-pakistan-deploy.pub`.
2. Test it: `ssh -i ride-pakistan-deploy -p 65002 u123456789@<host>`.
3. Pin the host key: `ssh-keyscan -p 65002 <host>`. Compare the fingerprint (`ssh-keygen -lf <(ssh-keyscan -p 65002 <host>)`) with what your own first manual connection showed, then paste the lines into `SSH_KNOWN_HOSTS`.
4. Paste the private key `ride-pakistan-deploy` into `SSH_PRIVATE_KEY` in both environments, then delete the local copy or keep it in your password manager.

## 4. First-time server setup (once per environment)

### Folders

```
<DEPLOY_PATH>/
  releases/20261108-120000-ab12cd34/   one folder per deploy; the last 5 are kept
  shared/.env                          you create this
  shared/storage/                      logs, sessions, cache, transfer proofs, backups (created by the first deploy)
  shared/uploads/                      admin-panel image uploads (public/images/uploads)
  current -> releases/<live release>   switched by each deploy
```

Nothing under `<DEPLOY_PATH>` is web-accessible except `current/public`. `.env`, storage, transfer proofs and backups sit outside the web root.

### Database (hPanel → Databases → MySQL Databases)

Create one database and one user per environment, e.g. `u123456789_rp_prod` / `u123456789_rp_prod_app` and `u123456789_rp_stg` / `u123456789_rp_stg_app`, each user with a long random password and access to **its own database only**. Hostinger grants all privileges on that one database. That's the least privilege shared hosting allows, and the app needs DDL for migrations. Never reuse the production user for staging.

### `.env`

```bash
ssh -p 65002 u123456789@<host>
mkdir -p <DEPLOY_PATH>/shared
nano <DEPLOY_PATH>/shared/.env      # start from .env.example, fill the table in section 1
chmod 600 <DEPLOY_PATH>/shared/.env
```

### Point the domain at `current/public`

Production (`ride-pakistan.com`): Hostinger serves `~/domains/ride-pakistan.com/public_html`. Replace that folder with a symlink. Do this right after the first successful deploy has created `current`:

```bash
cd ~/domains/ride-pakistan.com
mv public_html public_html.hostinger-default
ln -s <DEPLOY_PATH>/current/public public_html
```

Staging (`staging.ride-pakistan.com`): hPanel → Domains → Subdomains. Create it with a **custom folder outside `public_html`** (e.g. `staging_html`), because `public_html` is now the production release. Then replace that folder with a symlink to `<staging DEPLOY_PATH>/current/public` in the same way. Check in hPanel where the subdomain's folder ended up before you replace it.

`public/.htaccess` (Laravel's) is used by Hostinger's LiteSpeed server as is. Leave hPanel's "Force HTTPS" off: Cloudflare redirects to HTTPS, and doing it at both ends can cause redirect loops.

### Cron (hPanel → Advanced → Cron Jobs → Custom)

One job per environment, **every minute**:

```
* * * * * cd <DEPLOY_PATH>/current && <PHP_BIN> artisan schedule:run >> /dev/null 2>&1
```

That single entry runs everything in `routes/console.php`:

| What | When |
|---|---|
| Queue worker `queue:work --stop-when-empty --max-time=50 --tries=3` (emails, image variants, tracking events). There's no Supervisor, so it drains the queue and exits before the next minute | every minute |
| Card holds and unpaid transfers `holds:release`, waitlist offers `waitlist:process` | every minute |
| Ops alerts `monitor:operations` (section 8) | every 5 minutes |
| Reminders `notifications:send reminders`, voucher delivery `vouchers:deliver` | every 15 minutes |
| Thank-yous, weather `weather:check`, voucher purchases `vouchers:release` | hourly |
| Credits and vouchers expiry, backups clean 01:00 and run 01:30, slots 02:30, sitemap 03:15, anonymise 03:45, sign-in codes 04:00, transfer proofs 04:15, failed jobs older than 30 days 04:30 (UTC) | daily |
| Credit expiry warnings 10:00, lead digest 09:00 (Pakistan time) | daily |
| Backup health report `backup:monitor` (Monday 08:00 Pakistan time) | weekly |

File logs rotate daily (`LOG_STACK=daily`) and are deleted after `LOG_DAILY_DAYS`. The audit log and the notification log are kept permanently (accounting, PLAN §3.2).

### First deploy

1. Run the **Deploy** workflow on `main` with environment `staging` (Actions → Deploy → Run workflow). It creates `shared/storage`, migrates the empty database and switches `current`.
2. Seed the business data once: `cd <DEPLOY_PATH>/current && <PHP_BIN> artisan db:seed --force` (products, schedule rules and their slots, pages, FAQs, draft guides; settings use their defaults from `config/settings.php` until an admin changes them).
3. Create the first admin: `<PHP_BIN> artisan admin:create`, then set up MFA at `/<ADMIN_PATH>`.
4. Do the `public_html` symlink above, then open the site.
5. Repeat for production once staging is checked (section 13).

## 5. How a deploy works

`.github/workflows/deploy.yml`:

```
push to main ──► tests (ci.yml) ──► build ──► deploy-staging ──► deploy-production
                                                                 (waits for approval)
pull request ──► build only (proves the release builds; ci.yml runs the tests)
manual run   ──► deploy the chosen commit to one environment, or roll back
```

**Build** (Ubuntu runner, PHP 8.3 with GD):
1. Fails at once if GD can't write AVIF and WebP.
2. `composer install --no-dev --optimize-autoloader`. Composer's `post-autoload-dump` runs `filament:upgrade`, which publishes Filament's CSS, JS and fonts into `public/` (gitignored, so they exist only in the release). The build fails if they're missing.
3. `npm ci && npm run build` (fails without `public/build/manifest.json`).
4. `php artisan images:responsive` writes AVIF and WebP variants of the shipped photos to `public/images/_variants/`. The build fails if the command fails or no `.avif` or `.webp` file was produced. The variants are gitignored and are never made on the server.
5. Packs everything except `.git`, `node_modules`, tests, docs, `storage` and `.env` into `release.tar.gz` (kept 7 days as a workflow artifact).

**Deploy** (per environment, one at a time):
1. SSH with the pinned host key, then `rsync` the release into `releases/<UTC timestamp>-<sha>`.
2. `deploy/remote-deploy.sh` on the server:
   - links `shared/.env`, `shared/storage`, `shared/uploads` and `public/storage`
   - checks the built assets and AVIF variants are there
   - `artisan migrate --force`, then `artisan optimize` (config, routes, views, events)
   - switches `current` atomically (`ln -sfn` + `mv -T`)
   - `artisan responsecache:clear` and `artisan queue:restart`
   - deletes all but the last 5 releases

   Every step before the switch runs in the new folder while the old release keeps serving visitors. If any step fails, the site is untouched.
3. **Smoke test** from the runner: `/` and `/up` must answer 200 (5 tries). `/up` also queries the database. **If the smoke test fails, the job rolls back to the previous release automatically** and fails.

Production uses the same artifact staging got, after a required reviewer approves the `production` environment in the Actions run.

### Migrations

Migrations run before the switch while the old code is still live, and a rollback doesn't undo them. So every migration must work with the code that is already deployed:
- Add columns as nullable or with a default; add tables freely.
- Rename or drop in two deploys: first stop using the column (deploy), then drop it (next deploy).
- Never edit an existing migration.

## 6. Notes from the milestone prompt

- **Images and Filament assets are built in CI.** `public/images/_variants/` and `public/{css,js,fonts}/filament/` are gitignored; only the release artifact has them. Don't run `images:responsive` or `filament:upgrade` on the server instead of deploying: Hostinger's GD may not support AVIF.
- **The scheduler runs every minute** (one cron line, section 4). Without it no emails go out, holds never expire, and the ops alert can't fire either. UptimeRobot (section 8) is the outside check.
- **The Stripe webhook path must get through Cloudflare** (`POST /webhooks/stripe`, section 9). If Cloudflare challenges Stripe, payments are taken but orders are never fulfilled until Stripe retries succeed.

## 7. Rollback

**Automatic:** a failed smoke test rolls back on its own (section 5).

**By hand from GitHub:** Actions → Deploy → Run workflow → environment, action `rollback`. It runs `deploy/rollback.sh`: `current` goes back to the release before the live one, caches are rebuilt with the current `.env`, the page cache is cleared, workers restart, and the smoke test runs.

**By hand over SSH:**

```bash
ls <DEPLOY_PATH>/releases                                              # newest last
PHP_BIN=<PHP_BIN> bash <DEPLOY_PATH>/current/deploy/rollback.sh <DEPLOY_PATH>            # previous release
PHP_BIN=<PHP_BIN> bash <DEPLOY_PATH>/current/deploy/rollback.sh <DEPLOY_PATH> <release>  # a specific one
```

Rollback switches code only; the database stays as the newer release left it (see Migrations). To go forward again, deploy again (push or run the workflow).

## 8. Monitoring

- **Uptime: UptimeRobot (free, 5-minute HTTP checks)**, with an alert email to your ops inbox. Add three monitors for production, and optionally `/up` for staging:
  - `https://ride-pakistan.com/` (keyword monitor on "Ride Pakistan" catches a blank page)
  - `https://ride-pakistan.com/book`
  - `https://ride-pakistan.com/up`

  `/up` returns 500 when the app can't boot or the database is unreachable. If Cloudflare challenges UptimeRobot, add its IPs to the skip rule in section 9 (UptimeRobot publishes them) or use the smoke-test header (UptimeRobot can send custom headers on paid plans only).
- **Ops alerts (in the app, `monitor:operations`, every 5 minutes)** go to every admin:
  - **Queue stuck:** a job has been waiting more than 5 minutes (delayed jobs don't count). Usually the cron job is gone or failing.
  - **Transfers waiting:** a booking or gift-voucher bank transfer has waited more than 12 hours for review.

  Each alert emails when the problem starts, every 6 hours while it lasts, and once with "All clear". These emails are sent straight away, not through the queue, so a stuck queue still alerts. They need working mail settings.
- **Email failures:** every failed send is logged (`Email delivery attempt failed`, then `Email delivery failed`) with the kind of email, the job id and the error, never the contents or addresses. When an email fails for good, every admin gets "Emails are failing to send", at most once an hour (section 10).
- **Backups:** failures are emailed straight away, and a health report goes out every Monday (section 11).
- **Error tracking: deferred** (decided 2026-11-08, no budget). Errors go to `storage/logs/laravel-YYYY-MM-DD.log`; check it after each deploy and when an alert fires. To add it later: Sentry's free Developer plan works server-side with `composer require sentry/sentry-laravel` and `SENTRY_LARAVEL_DSN`, and needs no CSP or consent change because no browser script is added.

## 9. Cloudflare

DNS for `ride-pakistan.com` on Cloudflare, nameservers changed at the registrar.

| Setting | Value |
|---|---|
| DNS | `A @` and `A staging` → Hostinger server IP, **Proxied** (orange cloud). `www` CNAME → `ride-pakistan.com`, proxied, with a redirect rule `www` → apex. Mail records (section 10) **DNS only**. |
| SSL/TLS mode | **Full (strict)**. The origin needs a valid certificate: Hostinger's free SSL (hPanel → Security → SSL) for both host names, or a Cloudflare Origin CA certificate installed in hPanel. |
| Edge certificates | Always Use HTTPS on, minimum TLS 1.2, TLS 1.3 on. HSTS is sent by the app (PLAN §7), so leave Cloudflare's HSTS off to avoid two sources. |
| Speed | HTTP/3 (with QUIC) on, Brotli on (Free plans compress automatically), Early Hints optional. Rocket Loader **off** (it rewrites scripts and breaks the nonce CSP). Auto Minify isn't needed (Vite already minifies). |
| Caching | Cache rule: `/build/*` and `/images/*` "eligible for cache", edge TTL 1 month, respect origin headers. HTML is not cached at the edge (the app's page cache handles it, and account pages must never be cached). |
| WAF managed rules | Cloudflare Managed Ruleset on (Free plans get the free managed ruleset). |
| Stripe webhook | Custom rule **Skip**: `(http.request.uri.path eq "/webhooks/stripe" and http.request.method eq "POST")`, skipping "All remaining custom rules", "Managed rules", "Rate limiting rules" and "Super Bot Fight Mode". The app checks Stripe's signature, so the skip is safe. Optionally add `and ip.src in {Stripe webhook IPs}` (stripe.com/files/ips/ips_webhooks.txt) and keep that list current. |
| Deploy smoke test | Custom rule **Skip** with the same products for `any(http.request.headers["x-smoke-test"][*] eq "<SMOKE_TEST_TOKEN>")`. |
| Bot protection | **Free plan: keep Bot Fight Mode off.** It can't be skipped for a path, so it would challenge Stripe's webhooks and the deploy check. Forms are already protected by Turnstile, the honeypot and rate limits. **Pro plan:** Super Bot Fight Mode with "Definitely automated" set to Managed Challenge is fine, because the skip rule above can exclude the webhook. |
| Admin path | Optional: Cloudflare Access (Zero Trust, free for up to 50 users) on `/<ADMIN_PATH>*` and `/team/*`. |
| Turnstile | Widget with host names `ride-pakistan.com` and `staging.ride-pakistan.com`; keys in `.env`. |

The app trusts `X-Forwarded-For` and `X-Forwarded-Proto` only from Cloudflare's published IP ranges (`config/security.php`, checked 2026-11-08). Before launch, compare them with https://www.cloudflare.com/ips/ and set `TRUSTED_PROXIES` if they've changed. Without this, every visitor would share Cloudflare's IP in the rate limits.

## 10. Email: Resend (SPF, DKIM, DMARC)

Transactional email goes through **Resend** (`resend/resend-laravel`, the `resend` mailer in `config/mail.php`). Locally and in tests the mailer stays `log` (tests use `array`); staging and production set `MAIL_MAILER=resend`.

- **Sender:** `bookings@mail.ride-pakistan.com`, name "Ride Pakistan", on the domain verified in Resend, **`mail.ride-pakistan.com`**. Sending from a subdomain keeps Resend's reputation separate from the apex, where the Hostinger mailboxes live.
- **Reply-To:** `hello@ride-pakistan.com` on every email (the mailer's global `mail.reply_to`; no email sets its own). Replies land in the Hostinger inbox.
- **API key:** `RESEND_API_KEY` is a **server secret**: put it only in each server's `shared/.env`, never in the repository, a GitHub variable or a log. Use a key with "Sending access" for the one domain only. To rotate it: create a new key in Resend, update `shared/.env`, run `php artisan optimize`, then delete the old key.
- **Free plan: 100 emails a day** (and 3,000 a month). A busy weekend (confirmations, reminders, thank-yous, sign-in codes) can come close. Past the limit Resend refuses sends; the queue retries them with backoff (1, 5 and 15 minutes, then an hour; 5 attempts), and once they fail for good the admins get one "Emails are failing to send" email an hour. Watch Resend → Usage, and move to the paid plan before launch traffic.
- **What's queued:** every customer and team notification is queued (cron drains the queue every minute) with that retry policy. **Sign-in codes are sent straight away**, so signing in isn't held up by the cron queue; if Resend errors, the same email is queued (encrypted, because it holds the code) and retried after 30 s and 2 min (decided 2026-11-08). The ops, backup and email-failure alerts and `mail:test` are also sent straight away, so they still go out when the queue is stuck.
- **Test:** `php artisan mail:test <address>` sends a branded test email at once and prints Resend's email ID, or the error (addresses masked).
- The package's webhook route is **off** (`config/resend.php`, `RESEND_ROUTES`): nothing uses Resend webhooks, and without a signing secret the route would accept unsigned requests.

### DNS records (checked live on 2026-11-08)

The zone is still on Hostinger's nameservers (`artemis` / `hermes.dns-parking.com`). When it moves to Cloudflare (section 9), **copy these records exactly and keep them "DNS only"** (grey cloud), or mail stops verifying.

| Name | Type | Value | Purpose |
|---|---|---|---|
| `resend._domainkey.mail` | TXT | `p=MIGfMA0…` (the public key Resend shows under Domains → mail.ride-pakistan.com) | DKIM for `mail.ride-pakistan.com` |
| `send.mail` | CNAME | `send.forge.rmta.net` | Resend's bounce (Return-Path) domain: carries Resend's SPF (`v=spf1 ip4:… ~all`) and the feedback MX, so SPF aligns with the sender |
| `_dmarc` (apex) | TXT | `v=DMARC1; p=none; rua=mailto:hello@ride-pakistan.com` | **Existing record: keep it, don't add a second one.** It also covers `mail.` (subdomains inherit the apex policy), so there's no `_dmarc.mail` record. |
| `@` | TXT | `v=spf1 include:_spf.mail.hostinger.com ~all` | Apex SPF for the Hostinger mailboxes. Resend doesn't send as the apex, so it needs no change. **One SPF record per name only.** |
| `@` | MX | `mx1.hostinger.com` (5), `mx2.hostinger.com` (10) | Inboxes (hello@, the reply-to) |

If Resend ever shows different values (Domains → mail.ride-pakistan.com → DNS records), its dashboard wins; update this table.

**DMARC later:** after 2 to 4 weeks of clean aggregate reports to `hello@`, change the **same** apex record to `p=quarantine`, then `p=reject`. Never publish a second `_dmarc` record: receivers then ignore DMARC entirely.

Check: send a sign-in code or `mail:test` to the address https://www.mail-tester.com gives you (aim for 9/10 or better, with SPF, DKIM and DMARC passing), and to a Gmail address ("Show original": SPF, DKIM and DMARC all PASS, signed by `mail.ride-pakistan.com`).

**Live test 2026-11-08** (from this worktree, `MAIL_MAILER=resend` for the test only): `mail:test hello@ride-pakistan.com` and a real sign-in code (`RequestLoginCode`) were both accepted by Resend, which returned an email ID for each; the code was sent straight away, nothing queued.

## 11. Backups and restore

**What:** every night at 01:30 UTC (`backup:run`, `config/backup.php`), a zip with the database dump (`mysqldump --single-transaction`), `storage/app/transfers` (bank transfer proofs), `storage/app/private`, `storage/app/public` and `public/images/uploads` (admin uploads). Code and built assets aren't included; they're in git and the release.

**Encryption:** AES-256 zip with `BACKUP_ARCHIVE_PASSWORD`. **Without that key the backup isn't encrypted**, so set it in both environments, and keep it in the password manager: a lost password means unreadable backups.

**Where:** the `backups` disk, `shared/storage/app/backups/<APP_NAME>/` (private, outside the web root, survives deploys). **Retention 30 days** (`backup:clean` at 01:00). Over 5 GB in total, the oldest are deleted first.

**Decided 2026-11-08: no off-site copy for now.** The backups sit on the same server as the site, so they protect against mistakes (a bad migration, a deleted record) but **not** against losing the hosting account. Until an S3-compatible bucket is added (Cloudflare R2 or Backblaze B2: `composer require league/flysystem-aws-s3-v3`, an `s3` disk, then add it to `backup.destination.disks`):
- **Every week**, download the newest zip to a computer or cloud drive you control: `scp -P 65002 u123456789@<host>:<DEPLOY_PATH>/shared/storage/app/backups/<APP_NAME>/<newest>.zip .`
- Hostinger's own daily/weekly backups (hPanel → Files → Backups) are a second, independent copy.

**Health emails** go to `BACKUP_NOTIFICATION_EMAIL`: a failed backup or cleanup straight away, and every Monday a report from `backup:monitor` (healthy, or unhealthy if the newest backup is older than a day or they use over 5 GB). Successful nightly runs don't email.

### Restore (test it on staging before launch)

1. Pick an archive: `ls <DEPLOY_PATH>/shared/storage/app/backups/<APP_NAME>/`.
2. Extract it. Hostinger's `unzip` can't open AES zips, so use the PHP helper:
   ```bash
   cd <DEPLOY_PATH>/current
   read -s BACKUP_ARCHIVE_PASSWORD && export BACKUP_ARCHIVE_PASSWORD
   <PHP_BIN> deploy/extract-backup.php <archive.zip> ~/restore-tmp
   unset BACKUP_ARCHIVE_PASSWORD
   ```
   It holds `db-dumps/mariadb-<database>.sql` plus the files under their full server paths.
3. Database:
   - **Into a scratch database first** (create one in hPanel): `mysql -u <user> -p <scratch_db> < ~/restore-tmp/db-dumps/mariadb-<database>.sql`, then compare row counts with the live tables.
   - **Real restore:** `php artisan down`, import into the environment's database, then `php artisan up`. Bookings made after the backup are lost, so check Stripe and the transfer queue for anything paid since.
4. Files: copy the needed folders from `~/restore-tmp/<full path>/` back into `shared/storage/app/...` or `shared/uploads/`.
5. `php artisan optimize && php artisan responsecache:clear`, check the site, then `rm -rf ~/restore-tmp`.

**Tested locally on 2026-11-08** (worktree database `ride_pakistan_12`, MariaDB 13): `backup:run` produced an encrypted zip and verified it. The helper refused a wrong password and extracted with the right one, the dump imported into a scratch database, and all 50 tables matched row for row. **The staging restore is still to do** (section 13).

## 12. Incidents, briefly

| Symptom | Look at |
|---|---|
| Deploy failed before "Switching current" | Nothing changed for visitors. Read the job log, fix, push again. |
| Smoke test failed | It rolled back automatically. Look at `shared/storage/logs/laravel-*.log` on the server. |
| "Emails are failing to send" email, or customers say no email arrived | `php artisan mail:test you@example.com` over SSH shows Resend's exact error. Resend dashboard → Emails/Logs (daily limit of 100 on the free plan, domain still verified, key not revoked). Fix it, then `php artisan queue:retry all` resends the failed ones (stale sign-in codes are harmless: the customer asks for a new one). |
| "Background jobs are stuck" email | hPanel cron job present and pointing at `current`? Run `cd <DEPLOY_PATH>/current && <PHP_BIN> artisan schedule:run` by hand and read the output. |
| "Bank transfers waiting" email | Admin → Verify transfers / Verify voucher transfers. |
| Stripe payments not confirming | Stripe dashboard → Webhooks → recent deliveries (403 or a challenge means Cloudflare, section 9). |
| Site down (UptimeRobot) | `/up` 500 = app or database; Cloudflare 52x = origin unreachable or certificate (Full strict). |

Security incidents: `docs/SECURITY.md`.

## 13. Acceptance checklist

These need the real servers, so they're for you (or the next session once the values in section 1 exist):

- [ ] Section 1 values set; `staging` and `production` environments created, production with required reviewers and `main` only.
- [ ] Staging first deploy succeeds; seeded; admin created; site loads over Cloudflare Full (strict).
- [ ] Push to `main` deploys to staging automatically; the production job waits for approval and then deploys.
- [ ] **Rollback tested on staging:** deploy twice, run the `rollback` action, check the older release is live (`readlink <DEPLOY_PATH>/current`), deploy again.
- [ ] Cron runs: `holds:release` and `queue:work` show up in the logs; a test email arrives.
- [ ] Backup runs overnight on staging; the zip is encrypted (try opening it without the password).
- [ ] **Restore tested on staging** (section 11) and the date written here.
- [ ] Stripe test webhook to staging returns 200 through Cloudflare.
- [ ] UptimeRobot monitors green; mail-tester score 9+ with SPF, DKIM and DMARC passing.
- [ ] `php artisan mail:test hello@ride-pakistan.com` on staging and production prints a Resend email ID.
- [ ] Nameserver move to Cloudflare: the Resend and Hostinger mail records copied exactly (section 10) before switching.
- [ ] Cloudflare ranges in `config/security.php` still match cloudflare.com/ips.
