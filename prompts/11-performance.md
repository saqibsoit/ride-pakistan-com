# Prompt 11: Performance pass

Read `AGENTS.md` and `docs/PLAN.md` section 9.

1. Measure first: Lighthouse (mobile) on `/`, `/prices`, `/book`, `/faq`, a guide page; record results in `docs/PERFORMANCE.md`.
2. Fix: hero image `fetchpriority="high"` + preload, AVIF/WebP with correct `sizes`, fonts subset and preloaded, critical CSS inlined for above-the-fold if it measurably helps, defer non-critical JS, Livewire only on interactive pages, no render-blocking third parties.
3. Server: response cache on all public pages, `php artisan optimize`, query count checks (no N+1; add a test helper that fails on N+1 in key pages), DB indexes verified with `EXPLAIN` for booking queries.
4. Cache headers: static assets immutable for 1 year with hashed filenames; HTML cached at Cloudflare only for anonymous public pages (document the Cloudflare cache rules).
5. Re-measure and record before/after.

Acceptance criteria:
- Lighthouse mobile ≥ 95 Performance on all listed pages; LCP < 2.0 s, CLS < 0.05, TBT < 150 ms in lab.
- Public page TTFB from cache < 200 ms on staging.
