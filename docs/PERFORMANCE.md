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
