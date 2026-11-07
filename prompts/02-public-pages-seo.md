# Prompt 02: Public pages, CMS content and SEO/AEO/GEO

Read `AGENTS.md`, `docs/PLAN.md` sections 1, 2, 6 and 8, and `docs/reference/DESIGN-REFERENCES.md`. Brand look from our static page; page structure from the inspiration screenshots.

1. Content models + Filament resources: `pages` (slug, title, meta title, meta description, OG image, body blocks, published flag), `faqs` (question, answer, order, page association), product content fields on `products` (slug, short description, long description, who it’s for, what to bring list, what’s included list, good-to-know list, duration, group size, cover image, gallery, optional video URL, featured flag, category), `posts` for guides (title, slug, excerpt, body, cover image, author = trainer, published_at), and typed `settings` (business name, Instagram URL, email, schedule text, bank details, map pin URL, policies). Cache settings; bust cache on save.
2. Build these routes with real content from the reference page: `/` (plus a "This weekend" strip, wired to slots in milestone 5), `/experiences` (catalog with filter chips, inspiration 03), `/sessions/{product}` (product detail layout from inspiration 01; the booking widget is a placeholder until milestone 5), `/schedule` (week calendar shell, inspiration 02), `/prices` (lesson options, pricing with packs and validity, riding rules and booking policies, inspiration 02; reads from `products` and settings), `/trainer/omar-saleem`, `/faq`, `/guides`, `/guides/{slug}`, `/contact` (lead form posts to the leads module stub), `/expeditions`, `/privacy`, `/terms`, `/refund-policy`.
3. Every key page opens with a short factual answer block (what, where, when, price, who it’s for), then detail. Location is "Islamabad" only.
4. SEO service (`app/Support/Seo`): title template, meta description, canonical, robots, Open Graph, Twitter card per page with sensible fallbacks.
5. JSON-LD (generated from data, not hand-written strings): `Organization`, `SportsActivityLocation` (areaServed Islamabad, opening hours from schedule rules), `Person` for Omar Saleem, `Product` + `Offer` (PKR) for each active product, `FAQPage`, `BreadcrumbList`, `Article` for guides, `WebSite`. Validate output against schema.org types in tests.
6. `/sitemap.xml` (spatie/laravel-sitemap, regenerated on content change and nightly), `/robots.txt` (environment-aware: staging is `noindex` and disallows all), `/llms.txt` generated from settings, products and schedule.
7. Seed three starter guides as drafts with outlines only (I will write final copy): "Horse riding lessons in Islamabad: a beginner’s guide", "What to wear to your first horse riding lesson", "Private vs group riding lessons: which is right for you?".
8. Full-page response cache for all public GET pages; clear on any content, product, setting or schedule change.

Acceptance criteria:
- Each public page: unique title and description, one `h1`, canonical, valid JSON-LD (tested), included in sitemap.
- Lighthouse ≥ 95 in all four categories on `/`, `/prices`, `/faq` (mobile).
- Pest tests for SEO tags, sitemap, robots per environment and llms.txt content.
