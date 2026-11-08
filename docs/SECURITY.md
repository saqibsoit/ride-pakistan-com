# Security

How ride-pakistan.com is protected, what to do when something goes wrong, and how to back up and restore. The rules are in `docs/PLAN.md` §3 and §7. This file explains the controls behind them. Target: OWASP ASVS 4.0.3 Level 2 and the OWASP Top 10.

Last reviewed: 2026-11-08 (milestone 10).

## 1. Threat model

| Asset | What could go wrong | Main controls |
|---|---|---|
| **Payments** (Stripe Checkout, bank transfers) | Forged "paid" webhook; amount or currency tampering; double fulfilment; fake transfer proof approved; a staff member approving their own fraud | Stripe signature check, event id idempotency, amount, currency and reference re-checked before `FulfilOrder` (the only fulfilment path); mismatches flagged, never fulfilled. Line items built from the order, never the request. Transfers approved by **admins only**, recorded with verifier and time, and audited. Refunds through the Stripe API with a reason, never more than is left. |
| **Bookings and holds** | Overbooking by racing requests; holding seats forever; booking another customer's riders or credits | Seat checks under `lockForUpdate()` on the slot inside a transaction; hold expiry (`holds:release`); riders and credit packs always scoped to the signed-in user in the Actions (`CreateOrder`, `BookWithCredit`). |
| **Customer PII** (names, emails, phones, emergency contacts, transfer screenshots) | IDOR through order references or ticket codes; leaking screenshots; PII in logs or the audit log; data kept forever | Every account and checkout route loads records through `$user->orders()` / `$user->bookings()` and a policy (404 for anyone else). Livewire IDs are `#[Locked]`. Proofs live on a private disk outside the web root and are served only by a 5-minute signed URL to an admin, with a sandbox CSP. Encrypted casts for sensitive fields; the audit log redacts email and phone and never stores values for leads, notes or secrets. Data export, deletion and anonymisation (PLAN §7). Proofs are deleted 90 days after review (`transfers:prune-proofs`). CSP reports drop query strings and IPs. |
| **Admin panel** (Filament at `ADMIN_PATH`) | Credential stuffing; stolen password; an unattended signed-in screen; a malicious insider | Unguessable path; strong passwords with a breach (HIBP) check in production; required app-based MFA with recovery codes; 5 attempts a minute per IP plus 5 per 15 minutes per email; failed-login alert emails; 30-minute idle timeout (polling doesn't count); "sign out everywhere" (`session_version`); staff versus admin policies; every admin action audited. |
| **Customer sign-in** (email codes) | Code guessing; account enumeration; abuse of the email sender | 6-digit HMAC-stored codes, 10-minute expiry, single use, dead after 5 wrong guesses; request and submit rate limits; Turnstile; identical responses whether or not the account exists; team accounts never get codes. |
| **Public site and forms** | XSS; clickjacking; spam and lead flooding; third-party script compromise | Strict nonce CSP with `strict-dynamic` and no `unsafe-eval` on public pages, `frame-ancestors 'none'`; Blade escaping; Markdown escaped in lead emails; honeypot, Turnstile and rate limits on lead forms; tracking vendors load only after consent and only appear in the CSP for consenting visitors. |
| **Infrastructure** (Hostinger shared, Cloudflare) | Leaked `.env`; debug pages; vulnerable dependencies; lost data | `.env` outside the web root (document root is `public/`); `APP_DEBUG=false` (a critical log fires if it is ever on in production); `composer audit` and `npm audit` in CI and Dependabot weekly; encrypted daily backups outside the web root (section 6; off-site copy deferred, see `docs/DEPLOY.md`). |

## 2. Controls mapped to OWASP ASVS 4.0.3 (Level 2)

| ASVS chapter | How we meet it | Where |
|---|---|---|
| **V1 Architecture** | Business logic in single-purpose Actions; one fulfilment path (`FulfilOrder`); state changes through validated state machines that write the audit log; this threat model. | `app/Actions`, `OrderStateMachine`, PLAN §3.3 |
| **V2 Authentication** | Customers: one-time email codes (HMAC, expiry, single use, attempt cap, rate limits, Turnstile). Team: password at least 12 characters with mixed case, numbers and symbols, plus a HIBP check in production; required TOTP MFA with recovery codes; per-IP and per-email throttles; failed-login alerts. | `app/Actions/Auth`, `config/login.php`, `App\Filament\Auth\Login`, `RecordFailedTeamLogin` |
| **V3 Sessions** | Secure, HttpOnly, SameSite=Lax cookies; `SESSION_ENCRYPT=true` in production; regenerated on sign-in; `session_version` ends every session on "sign out everywhere" and deletion; 30-minute admin idle timeout. | `config/session.php`, `EnsureSessionIsCurrent`, `EnforceAdminIdleTimeout` |
| **V4 Access control** | Policies on every model; deny by default; staff versus admin split (proofs, approvals, refunds, conversion and deletes are admin only); IDOR-safe lookups; `#[Locked]` Livewire state; signed URLs for proofs. | `app/Policies`, route table below, `tests/Feature/Security/IdorTest.php` |
| **V5 Validation and encoding** | Form Requests and Livewire rules on all input; `$fillable` on every model (role is never mass assignable); Blade escaping; Markdown escaped in emails built from visitor text; uploads sniffed, re-encoded and size-checked. | `app/Http/Requests`, `SubmitBankTransfer`, `resources/views/mail/new-lead.blade.php` |
| **V6 Cryptography** | Laravel encrypter (AES-256-CBC with MAC) for encrypted casts and MFA secrets; HMAC-SHA256 for login codes; bcrypt for passwords; key rotation with `APP_PREVIOUS_KEYS` (section 5). | `User`, `Rider` casts, `app/Auth/LoginCodes` |
| **V7 Errors and logging** | No stack traces with `APP_DEBUG=false` (tested); audit log (`spatie/laravel-activitylog`) for every admin action, auth event and order or payment transition; CSP violations to `storage/logs/csp.log`; no secrets or full PII in logs. | `activity_log`, `config/logging.php` |
| **V8 Data protection** | PII minimisation; `Cache-Control: no-store, private` on account and checkout pages; data export and deletion; anonymisation after retention; proof retention 90 days; encrypted backups. | PLAN §7, `PruneTransferProofs`, `AnonymiseAccount` |
| **V9 Communications** | HTTPS everywhere behind Cloudflare Full (strict); HSTS two years with `includeSubDomains; preload`; `upgrade-insecure-requests`. | `AddSecurityHeaders`, `config/security.php` |
| **V10 Malicious code** | Dependencies pinned (`composer.lock`, exact npm versions), audited in CI, Dependabot; no third-party script without consent and a CSP entry; the admin panel loads no third-party fonts or avatars. | `.github/workflows/ci.yml`, `config/csp.php` |
| **V11 Business logic** | Seat locking and holds; amount re-checks; idempotent webhooks; refunds never exceed what is left; rate limits on every write route (tested). | `HoldSeats`, `HandleStripeEvent`, `RateLimitTest` |
| **V12 Files** | Private `transfers` disk outside `public/`, random names, MIME sniffing, re-encoding with EXIF stripped, PDF magic bytes, 5 MB cap, 5-minute signed admin URL, sandbox CSP, `nosniff`. The local disk's signed-URL serving is off. | `TransferProofs`, `TransferProofController`, `config/filesystems.php` |
| **V13 API and web services** | Stripe webhook: signature-verified, CSRF-exempt only there, no session, rate limited. CSP report endpoint: no session, 16 KB cap, rate limited. | `routes/web.php` |
| **V14 Configuration** | Security headers on every response (cached pages too); `ADMIN_PATH` required in production; secrets only in `.env`; `APP_DEBUG=false`; `.env` git-ignored. | `AddSecurityHeaders`, `.env.example`, `FilesAndConfigTest` |

## 3. Security headers and CSP

`App\Http\Middleware\AddSecurityHeaders` runs first in the `web` group and in the admin panel's middleware, outside the full-page cache, so cached pages get their own nonce and a policy that matches the visitor's consent. Headers:

- `Content-Security-Policy`, built by `App\Support\Csp\PolicyBuilder` from `config/csp.php`:
  - **Public pages** (site, account, checkout, check-in): `script-src 'nonce-…' 'strict-dynamic'`, `style-src 'self' 'nonce-…'`, `default-src 'self'`, `object-src 'none'`, `base-uri 'none'`, `frame-ancestors 'none'`, `form-action 'self' https://checkout.stripe.com`, Turnstile in `script-src`/`frame-src`/`connect-src`, `upgrade-insecure-requests` on HTTPS, `report-uri` and `report-to`. Alpine (`@alpinejs/csp`) and Livewire (`livewire.csp.esm`) use their CSP builds, so there is no `unsafe-eval`. Components are registered by name; markup holds only names and simple property paths.
  - **Admin panel**: the same, plus `'unsafe-eval'` (Filament evaluates Alpine expressions) and `style-src 'unsafe-inline'` (Filament's inline style attributes). The panel is behind MFA and is never framed. It loads Livewire's standard script itself (render hooks), because asset injection is off for the public bundle.
  - **Meta Pixel and GA4** are added only when the vendor is configured **and** the request carries `rp_consent=granted`. Accepting the banner reloads the page so the new policy applies; queued conversion events survive the reload (sessionStorage), and each fires once by event id.
  - `CSP_REPORT_ONLY=true` sends the same policy as `Content-Security-Policy-Report-Only` for a staging trial. `CSP_ENABLED=false` switches it off (emergency only).
- `Strict-Transport-Security: max-age=63072000; includeSubDomains; preload` (HTTPS only).
- `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Cross-Origin-Opener-Policy: same-origin`, and a `Permissions-Policy` denying the camera, microphone, geolocation, payment, USB and other unused features.
- Routes without the `web` group (Stripe webhook, CSP reports) use `AddStatelessSecurityHeaders`.
- The transfer proof response keeps its own `default-src 'none'; img-src 'self'; sandbox` policy.

**Adding a third-party service:** add it to `config/csp.php` `third_party` with its purpose, pages and sources (and `requires` if it needs consent), load it with the page nonce, and add a test. Never add `'unsafe-inline'` to `script-src`.

**Grade targets:** securityheaders.com A+ and Mozilla Observatory A+ on staging, checked in milestone 12 once staging is up. HSTS preload submission (hstspreload.org) happens after production has served the header for a while without problems. `includeSubDomains` covers every subdomain of ride-pakistan.com, so all of them must serve HTTPS first.

## 4. Routes and authorization

| Route group | Auth | Authorization | Rate limit | Input |
|---|---|---|---|---|
| Public pages (cached) | none | none | n/a (GET) | none |
| `POST /contact`, `POST /prices/question` | none | none | `leads` (3/min, 20/day per IP) | `StoreLeadRequest`, honeypot, Turnstile |
| `/login`, `/login/code`, `/login/restore` | guest | none | `login-code-request`, `login-code-verify`, `account-actions` | Form Requests |
| `POST /logout` | auth | own session | `account-actions` | none |
| `/account/*` | auth | scoped to `$request->user()` + policies (404 otherwise) | `account-actions` (20/min), `account-export` (5/h) | Form Requests, Livewire rules |
| `/checkout/{reference}/*` | auth | `$user->orders()` + `OrderPolicy` `view`/`pay` | `checkout` (10/min), `transfer-upload` (10/h) | `UploadTransferProofRequest` |
| `/checkin/{code}` | team (MFA) | gate `check-in` | `checkin` (60/min) | none |
| `/{admin}/transfers/{id}/proof` | auth + signed (5 min) | `BankTransferPolicy::viewProof` (admins) | n/a | none |
| `/{admin}` Filament | team + MFA | resource policies | Filament login limits + per-email limit | Filament forms |
| `POST /webhooks/stripe` | Stripe signature | n/a | `stripe-webhook` (300/min) | signature, then event checks |
| `POST /csp-report` | none | n/a | `csp-report` (30/min per IP) | 16 KB cap, fields whitelisted |

`tests/Feature/Security/RateLimitTest.php` fails if any write route has no throttle.

## 5. Secrets and key rotation

- Secrets live only in `.env` (outside the web root, git-ignored). Prices, bank details and keys come from settings or config, never from code.
- **Rotating `APP_KEY`** (after a suspected leak, or yearly):
  1. Generate a new key: `php artisan key:generate --show`.
  2. Set `APP_PREVIOUS_KEYS` to the old key (comma separated if there are several), then set `APP_KEY` to the new one.
  3. `php artisan config:cache`. Encrypted casts, MFA secrets and cookies encrypted with the old key still decrypt through the previous keys.
  4. Re-encrypt stored values: re-save encrypted model attributes with a one-off command (`User` MFA secrets and recovery codes, `Rider` encrypted fields), then remove the old key from `APP_PREVIOUS_KEYS`.
  5. Effects: everyone's session ends unless `SESSION_ENCRYPT` values decrypt with the previous key; outstanding sign-in codes (HMAC keyed with the app key) stop working, so customers just request a new one; signed URLs (proofs) expire anyway.
- **Rotating Stripe keys or the webhook secret:** roll the key in the Stripe dashboard, update `.env`, `php artisan config:cache`, then `php artisan stripe:check` (test keys) or a test webhook.
- **Other secrets** (`TURNSTILE_SECRET_KEY`, `META_CAPI_TOKEN`, mail credentials, `BACKUP_ARCHIVE_PASSWORD`): replace in the provider, update `.env`, `config:cache`. Keep the backup password in the password manager: without it the backups can't be opened.

## 6. Backup and restore

Backups use `spatie/laravel-backup`, set up in milestone 12 (`config/backup.php`, full runbook in `docs/DEPLOY.md` section 11):

- **What:** the database and private files (`storage/app/transfers`, `storage/app/private`, uploaded images). Not `vendor/`, `node_modules/` or caches.
- **How often and how long:** daily at 01:30 UTC, encrypted with `BACKUP_ARCHIVE_PASSWORD` (AES-256 zip), kept 30 days on the private `backups` disk (the server's shared storage, outside the web root). **Decided 2026-11-08: no off-site disk yet.** The backups don't survive losing the hosting account, so download one every week until an S3-compatible bucket is added.
- **Monitoring:** failures are emailed to `BACKUP_NOTIFICATION_EMAIL` straight away, and `backup:monitor` sends a health report every Monday (unhealthy when the newest backup is older than a day or they use over 5 GB).

**Restore procedure** (practise on staging before launch and after any big change):

1. Put the site in maintenance mode: `php artisan down --secret=<random>`.
2. Take the zip from `shared/storage/app/backups/` (or your weekly downloaded copy) and extract it with `BACKUP_ARCHIVE_PASSWORD=… php deploy/extract-backup.php <zip> <folder>` (stock `unzip` can't open AES zips).
3. Database: `mysql -u <user> -p <database> < db-dumps/<file>.sql` (into an empty database, or a new one that `.env` then points to).
4. Files: copy `storage/app/transfers` and `storage/app/private` back, keeping ownership and permissions (the web server user, `0700` folders).
5. `php artisan migrate --force` (only if the code is newer than the backup), `php artisan optimize`, `php artisan responsecache:clear`.
6. Check: sign in to the admin panel, open a recent order, open a transfer proof, run `php artisan stripe:check` on staging, and check that holds and reminders run (`php artisan schedule:list`).
7. `php artisan up`. Record what was restored and from which date in the incident log.

Payments made in Stripe after the backup's time are recovered by replaying webhooks from the Stripe dashboard (Developers → Events → resend); `stripe_events` idempotency makes this safe.

## 7. Incident response

1. **Detect.** Sources: failed-login alert emails, ops alerts (stuck queue, transfers waiting), `storage/logs` (error tracking is deferred), UptimeRobot alerts, CSP reports (`storage/logs/csp.log`), Stripe dashboard alerts, a customer report, the audit log.
2. **Contain** (minutes):
   - Suspected admin compromise: change that account's password, reset its MFA (another admin, Customers → user), and use "sign out everywhere" (or bump `session_version`). Change `ADMIN_PATH` if the path leaked. Cloudflare: block the IPs or put the admin path behind Cloudflare Access.
   - Leaked secret: rotate it (section 5).
   - Active attack on a form or endpoint: tighten the rate limit or block in Cloudflare (WAF rule); `php artisan down` if needed. Never disable CSRF, CSP or rate limits to "make something work".
3. **Investigate.** Audit log (Admin → Audit log, `activity_log` table: `auth`, `payments`, `orders`, `leads`, `people`, `content` logs), web server and Laravel logs, Stripe events, Cloudflare logs. Note times in UTC and Pakistan time.
4. **Recover.** Fix the cause, restore data if needed (section 6), check payments against Stripe and the bank statement, re-enable features.
5. **Notify.** Tell Destination Pakistan's owner the same day. If personal data was exposed, tell affected customers without undue delay (what happened, what data, what we did, what they should do) and meet any legal duty under Pakistan's data protection rules in force at the time. Tell Stripe if card payments were affected.
6. **Learn.** Within a week, write a short post-incident note in `docs/` (timeline, cause, fixes, follow-ups) and add a regression test.

Contacts: the site owner (Destination Pakistan) and the developer on call. Keep the current names and numbers in the password manager, not in this repository.
