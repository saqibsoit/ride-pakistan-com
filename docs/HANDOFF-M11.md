# Handoff: milestone 11 (performance pass)

Status on 2026-11-09. Branch `feat/m11-performance`, pushed and up to date with `origin`. **PR #23** (https://github.com/saqibsoit/ride-pakistan-com/pull/23) was opened earlier today, before the pause, and is **not merged**. CI on it (lint, PHPStan, tests on MariaDB, audits) **passes** on the latest commit `d25b2ff`.

## Done

All five steps of `prompts/11-performance.md` are done for everything that can be done locally. Details are in `docs/PERFORMANCE.md`.

1. **Baseline measured** on every public page, including the pages added after the prompt: `/experiences`, `/sessions/{product}`, `/schedule`, `/gift-vouchers`, and checkout with discounts. Lighthouse mobile, built assets, HTTPS on Herd, median of 3.
2. **Front end:**
   - Shorter Alpine start-up task: the header no longer forces a layout, and tracking and attribution start later.
   - The hero image is preloaded with high priority and no longer fades in from 60% opacity.
   - The body font is preloaded and cover images are prioritised.
   - Turnstile on `/prices` loads on demand. The nonce is kept and no inline script was added.
3. **Server:**
   - Full-page cache:
     - Seat pages (`/`, `/schedule`, `/sessions/*`) are versioned by a "seats version" instead of a full clear on every booking or hold.
     - Campaign and click tags are ignored in the cache key.
     - Signed-in visitors and visitors with flash data never get or leave a cached copy. This fixes one-off tracking events being cached for everyone.
     - Every cached hit gets a fresh nonce (tested).
   - Queries:
     - Query budgets with an N+1 guard on every key page.
     - EXPLAIN checks on the booking queries: all indexed, so no new indexes were needed.
     - Fixed the booking flow N+1 (11 → 6 queries); review summaries are one aggregate query.
   - `php artisan optimize` was verified.
4. **Cache headers:**
   - Public HTML is sent `no-cache, private`. It is never cached at Cloudflare, because each page carries its own CSP nonce (decided).
   - Hashed build files are cached for a year (immutable), images for 30 days (`public/.htaccess`).
   - The Cloudflare rules and staging checks are in `docs/DEPLOY.md` (§9 and the §13 checklist).
5. **Re-measured.** Before/after is recorded in `docs/PERFORMANCE.md`. PLAN §9 and the CHANGELOG are updated.

Commits on the branch:
- `86fd50e`: baseline, query-budget and index tests, booking flow N+1.
- `6b1ba7f`: front end.
- `5bbe6fe`: cache, headers and queries.
- `10b1e20`: docs.
- `d25b2ff`: hero preload fallback when no image variants exist. This was the only CI failure, now fixed.

## In progress

Nothing half-done in code. What's left needs the real server or your decision (below).

## Measurements so far (local, Lighthouse mobile, median of 3)

| Page | Perf before → after | LCP ms after | CLS | TBT ms before → after |
|---|---|---|---|---|
| `/` | 99 → 100 | 1699 | 0 | 121 → 33 |
| `/experiences` | 99 → 99 | 1567 | 0 | 122 → 122 |
| `/sessions/single-session` | 99 → 99 | 1715 | 0 | 108 → 78 |
| `/schedule` | 100 → 100 | 1559 | 0 | 116 → 37 |
| `/prices` | 98 → 100 | 1560 | 0 | 139 → 28 |
| `/faq` | 99 → 100 | 1555 | 0 | 87 → 27 |
| guide | 99 → 99 | 1712 | 0 | 71 → 77 |
| `/gift-vouchers` | 98 → 100 | 1565 | 0 | 151 → 51 |
| `/book` | 97 → 99 | 1900 | 0 | 161 → 27 |
| checkout with discounts | 96 → 99 | 1556 | 0 | 192 → 94 |

- **Lab targets:** all met (Performance ≥ 95, LCP < 2.0 s, CLS < 0.05, TBT < 150 ms).
- **JavaScript on marketing pages:** 25.8 KB gzipped, against a 50 KB budget.
- **Cached TTFB locally:** about 150–210 ms (with occasional spikes to about 320 ms), the same as `/up` on this machine. **Staging TTFB (target < 200 ms) is not measured yet.**

## Next steps, in order

1. **Review and merge PR #23** (CI is green). Then delete `feat/m11-performance`.
2. **On staging, after the next deploy** (`docs/DEPLOY.md` §13):
   - Check the cache headers with `curl -sI`:
     - hashed `/build/*`: `immutable` and `cf-cache-status: HIT`;
     - `/images/*`: 30 days;
     - HTML: `no-cache, private` and `cf-cache-status: DYNAMIC`.
   - If LiteSpeed ignores the `.htaccess` header rules, set the TTLs in Cloudflare's cache rules instead.
   - Measure cached TTFB from Pakistan or a nearby region (target < 200 ms) and add it to `docs/PERFORMANCE.md`.
3. **Set the Cloudflare cache rules** from DEPLOY.md §9 when the zone moves to Cloudflare. Don't add a "cache everything" rule for HTML.
4. **Optional, local only:** update your `.env` to the mail values from `.env.example`:
   - `MAIL_FROM_ADDRESS="bookings@mail.ride-pakistan.com"`
   - `MAIL_REPLY_TO_*`

   Two mail tests fail locally until then; they pass in CI. Nothing in the repo needs to change.
5. **Next milestone:** `prompts/13-launch.md` (QA and launch checklist). Milestone 12 (deploy) is already merged.

## For you to decide

- **PR #23:** it's open as asked earlier today. Leave it open for review, or should I turn it into a draft until you're back?
- **Staging TTFB:** if it misses 200 ms on Hostinger shared hosting, the options are:
  - accept it (the HTML still comes from the page cache);
  - move sessions off file storage for public pages;
  - revisit edge HTML caching with a Cloudflare Worker that rewrites the nonce. You turned that down for now because it adds a security-critical component.
- **Guides:** the three seeded guides are drafts (no `published_at`) in the local database. Publishing them is a content decision, tracked in `docs/OPEN-QUESTIONS.md` if the copy still needs confirming. I published one locally only to measure it, then set it back to draft.
- **Old branches:** `feat/m10d-*` is deleted. `feat/m10e-checkout-discounts` and `feat/m10e-transfer-refunds` (both merged) are still on GitHub and on your machine. Delete them?

## Local environment changes made today (not in git)

- **Local dev database:** ran the 11 pending migrations (10d, 10e, 10f and the transfer refunds) so `/gift-vouchers` and checkout could render. It now also has test users and orders created by the checkout measurement helper (e-mails `perf-…@example.com`).
- **Composer:** ran `composer install` to add `resend/resend-php`, which #20 added.
- **Assets and caches:** `public/build` was rebuilt with `npm run build`. The page cache was cleared.
