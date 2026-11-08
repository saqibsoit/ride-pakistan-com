# Performance (milestone 11)

Targets (PLAN §9, prompt 11): Lighthouse mobile Performance ≥ 95 on every public page, LCP < 2.0 s, CLS < 0.05, TBT < 150 ms in the lab; marketing pages under 50 KB of JavaScript (gzipped); public page TTFB from cache < 200 ms on staging.

## How we measure

- Local Herd site over HTTPS (`herd secure`), **built assets** (`npm run build`; no Vite dev server, so no `public/hot`), Herd Studio's injected script blocked (`--blocked-url-patterns="*herd-studio*"`).
- Lighthouse CLI, mobile preset (simulated throttling: 4× CPU, slow 4G), Performance category only.
- Each page three times; the **median** is recorded. Lighthouse sometimes fails to record a trace on this machine (`NO_NAVSTART`); failed runs are retried with another headless mode and never counted.
- Public pages are warmed first, so the numbers are for the full-page cache, the way visitors get them.
- Checkout is measured signed in, on a pending order with a promo code and a partial gift voucher applied (local helper builds the session cookie; passed with `--extra-headers`).
- Query counts come from `tests/Feature/Performance/QueryBudgetTest.php` (cache miss, seeded data plus bookings and a dozen reviews per product). Set `QUERY_BUDGET_REPORT=1` to print them.

## Baseline (before, 2026-11-09)

| Page | Perf | FCP ms | LCP ms | CLS | TBT ms | Server ms |
|---|---|---|---|---|---|---|
| `/` | 99 | 1307 | 1776 | 0 | 121 | 264 |
| `/experiences` | 99 | 1245 | 1702 | 0 | 122 | 686 |
| `/sessions/single-session` | 99 | 1264 | 1714 | 0 | 108 | 566 |
| `/schedule` | 100 | 1262 | 1562 | 0 | 116 | 311 |
| `/prices` | 98 | 1260 | 1584 | 0 | 139 | 351 |
| `/faq` | 99 | 1246 | 1546 | 0 | 87 | 390 |
| `/guides/{slug}` | 99 | 1171 | 1621 | 0 | 71 | 806 |
| `/gift-vouchers` | 98 | 1166 | 1466 | 0 | **151** | 633 |
| `/book` | 97 | 1554 | 1704 | 0 | **161** | 688 |
| `/checkout/{ref}` with discounts | 96 | 1286 | 1661 | 0 | **192** | 469 |

Every page already met the Performance, LCP and CLS targets. Three missed TBT: the long task is Alpine starting up (`app.js`, about 400 ms under the 4× CPU throttle), most of it style and layout work, in one task.

JavaScript on marketing pages: 25.5 KB gzipped (`app.js` 22.8 KB plus a shared 2.8 KB chunk). CSS: 8.9 KB gzipped, the only render-blocking file.

Server: locally even `/up` takes about 300 ms to first byte (Windows Herd, no config or route cache), so cached pages sit at that floor here. Staging TTFB is measured after deploy (see the end of this file).

Queries per page (cache miss): home 8, experiences 5, product page 10, schedule 6, prices 6, FAQ 5, guides 4, guide 5, gift vouchers 3, booking flow 11 (one product lookup repeated 6 times: N+1), checkout with discounts 12.

## What changed

- **Main thread (TBT).** The site header read the scroll position while Alpine was still applying bindings, forcing a synchronous layout inside Alpine's start-up task; it now reads it on the next frame. Tracking and lead attribution start in a later task. On checkout the start-up long task went from about 400 ms to 120 ms (4× CPU throttle).
- **LCP.** The hero photo is preloaded from the head (`<link rel="preload" as="image" imagesrcset … fetchpriority="high">`) and no longer fades in from 60 % opacity (scale only, still off with reduced motion). Product and guide covers load with high priority. The body font (Instrument Sans) is preloaded next to the display font.
- **Turnstile** on the `/prices` question form loads only when the form comes near the viewport or gets focus (`turnstile` Alpine component; the script is created by our nonced bundle and carries the nonce).
- **Full-page cache.**
  - A booking, hold or slot change no longer empties the whole cache. It moves a "seats version" that only the seat pages (`/`, `/schedule`, `/sessions/*`) carry in their cache key (`PageCache`, `PublicPageHasher`). Those pages keep copies for an hour, others for the usual week. Content changes still clear everything.
  - Campaign and click tags (`utm_*`, `fbclid`, `gclid`, …) are left out of the key and the remaining parameters are sorted, so ad clicks share one copy.
  - Signed-in visitors and visitors with flash data or validation errors never get or leave a cached copy (`PublicCacheProfile`). This also fixes a bug where a visitor's one-off tracking events could be cached for everyone.
  - Every cached hit still gets its own CSP nonce (tested).
- **Queries.** The booking flow looked its product up 6 times per render (one N+1); now once per slug per request (11 → 6 queries). Review summaries are one aggregate query; the gift voucher pages reuse the request's product list; checkout loads its relations once.
- **Headers.** Public HTML: `Cache-Control: no-cache, private`. Static files (`public/.htaccess`): hashed Vite files `public, max-age=31536000, immutable`; images, icons and the manifest 30 days. Cloudflare rules are in `docs/DEPLOY.md` §9.
- **Not done, on purpose.**
  - Critical CSS isn't inlined: first paint is already about 1.1 s and the CSS is one 8 KB (gzip) file, so it wouldn't measurably help.
  - No extra small logo variant: the logo is a 7 KB AVIF.
  - HTML isn't cached at Cloudflare (decided): visitors would share one CSP nonce.

## After (2026-11-09)

| Page | Perf | FCP ms | LCP ms | CLS | TBT ms | Server ms |
|---|---|---|---|---|---|---|
| `/` | 100 (99) | 1099 | 1699 | 0 | 33 (121) | 184 |
| `/experiences` | 99 (99) | 1117 | 1567 | 0 | 122 (122) | 335 |
| `/sessions/single-session` | 99 (99) | 1115 | 1715 | 0 | 78 (108) | 343 |
| `/schedule` | 100 (100) | 1109 | 1559 | 0 | 37 (116) | 270 |
| `/prices` | 100 (98) | 1110 | 1560 | 0 | 28 (139) | 231 |
| `/faq` | 100 (99) | 1105 | 1555 | 0 | 27 (87) | 256 |
| `/guides/{slug}` | 99 (99) | 1112 | 1712 | 0 | 77 (71) | 259 |
| `/gift-vouchers` | 100 (98) | 1115 | 1565 | 0 | 51 (151) | 473 |
| `/book` | 99 (97) | 1557 | 1900 | 0 | 27 (161) | 606 |
| `/checkout/{ref}` with discounts | 99 (96) | 1106 | 1556 | 0 | 94 (192) | 238 |

Before values are in brackets. Every page now meets every lab target: Performance ≥ 99, LCP ≤ 1.9 s, CLS 0, TBT ≤ 122 ms.

JavaScript on marketing pages: 25.8 KB gzipped (`app.js` 22.8 KB plus a shared chunk of 3.0 KB; the Turnstile loader added 0.3 KB). CSS: 8.3 KB gzipped.

Cached TTFB locally (five requests each, warm cache): `/` 152–319 ms, `/prices` 152–213 ms, `/schedule` 169–254 ms; `/up` 151–230 ms. On this machine the page cache sits at the framework floor. **Staging TTFB (target < 200 ms) still has to be measured on the real server**; see the acceptance checklist in `docs/DEPLOY.md` §13.

## Index checks

`tests/Feature/Performance/IndexUsageTest.php` runs EXPLAIN on the booking engine's hot queries and fails if a table in the plan has no usable index. The queries:
- the seat-lock reads of bookings, holds and the waitlist;
- the availability list with its seat-count subqueries;
- usable credit packs;
- expiring orders and holds;
- FAQs by page or product.

All of them have one: `bookings (slot_id, status)`, `booking_holds (slot_id, expires_at)`, `waitlist_entries (slot_id, status, id)`, `slots (status, starts_at)`, `credit_packs (user_id, status, expires_at)`, `orders (status, expires_at)`. No new indexes were needed.

## Production build check

`php artisan optimize` (config, events, routes, views, Blade icons, Filament) built cleanly on 2026-11-09; `OptimizeReadinessTest` guards the config cache.
