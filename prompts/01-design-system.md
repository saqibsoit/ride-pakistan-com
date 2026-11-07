# Prompt 01: Design system and layout

Read `AGENTS.md`, `docs/PLAN.md` and `docs/reference/DESIGN-REFERENCES.md`. Reference design: the existing static landing page (I will paste or add it at `docs/reference/index.html` with its images in `docs/reference/images/`). Match its look and feel, but build it properly as components.

Brand:
- Colours: earth `#1F140B`, earth-2 `#2A1B10`, saddle `#8B5A2B`, sand `#F4E8C1`, sand-dim `rgba(244,232,193,.72)`, line `rgba(244,232,193,.16)`.
- Display font: Big Shoulders Display (700/800/900). Body font: Instrument Sans (400–700). Self-host both as subset WOFF2 in `resources/fonts`, `font-display: swap`, preload the display font. No Google Fonts requests.
- Logo: `public/images/logo.png` (sand coloured mark). Generate favicons and an Apple touch icon from it.

Build:
1. `resources/css/tokens.css` with CSS variables for colours, type scale (fluid `clamp()`), spacing and radii, wired into Tailwind’s theme. No colours or sizes outside tokens.
2. Base layout `components/layouts/app.blade.php`: skip link, sticky header (logo + nav + "Book a session" button), footer (Instagram, ridepakistan.co, email, legal links, "A Destination Pakistan company"), slots for SEO head content.
3. Blade components: experience card (photo, price badge, category, featured tag, meta row, Details/Book buttons), filter chips, sticky price-and-book box (desktop) and bottom booking bar (mobile), seats-left indicator with "Selling fast" state, week calendar slot card, button (primary, ghost), section heading, fact strip, step list, price row, FAQ item (`<details>`), responsive image (`<x-img>`: AVIF/WebP `srcset`, width/height, lazy by default, `priority` prop for hero), breadcrumb, alert, form field (label, hint, error), badge.
4. Responsive image pipeline: an artisan command and an upload hook that create AVIF + WebP variants at 480/768/1200/1800 widths.
5. Accessibility: visible focus styles, 4.5:1 contrast checked, `prefers-reduced-motion`, keyboard-friendly mobile menu built with Alpine.
6. The hero headline must scale by viewport width AND height so it never collides with the header on short wide screens.
7. Create `/styleguide` (only in local environment) showing all components.

Acceptance criteria:
- Lighthouse on `/styleguide`: Accessibility 100, Best Practices 100.
- No layout shift from fonts or images (CLS ≈ 0).
- Pest tests render each component without errors.
