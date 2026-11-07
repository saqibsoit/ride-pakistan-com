# Design references

Two kinds of reference. Do not mix them up.

## 1. Look and feel: `docs/reference/index.html`
Our own landing page. It defines the brand: colours, typography (Big Shoulders Display + Instrument Sans), photography style, spacing, tone of voice and the story order of the homepage. All pages must look like this brand. Do not copy the visual style of the inspiration sites below.

## 2. Structure and features: `docs/reference/inspiration/`
Use these only for page structure, booking UX and information architecture, restyled in our brand.

### 01-event-detail-page.png (event/ticket page)
Use for each bookable product page (`/sessions/{product}`):
- Title block with a boxed "From PKR X" price and a primary "Book" button that stays visible (sticky on desktop, bottom bar on mobile).
- Key facts block next to a hero image: day and time slots, location (Islamabad), duration, level.
- Short factual intro, then sections: "The session" (timeline), "What to bring" (kit list), "Your booking includes" (bullets), "Good to know" (policies: minimum age, cancellation, photography consent, no transport provided).
- Inline booking widget on the page: pick a date and slot, rider count stepper, live seats remaining ("3 spots left"), a "Selling fast" badge when ≤ 2 seats are left, then checkout.
- "More ways to ride" grid at the bottom linking the other products and the expeditions site.

### 02-weekly-schedule-and-pricing.png (weekly class calendar + lesson options + pricing + policies)
Use for `/schedule` and `/prices`:
- Week view calendar (Sat and Sun columns emphasised; weekdays shown as "No sessions" or hidden on mobile), previous/next week arrows, filters for product type and trainer. Each slot card shows time, product, trainer and seats left, and opens the booking widget.
- "Lesson options" section: one short paragraph per product describing who it’s for and what level is expected.
- Pricing grouped by product with pack options (single, 8-class, 16-class, private), showing per-session price and validity.
- "Riding rules" and "Booking policies" sections (helmet rule, closed shoes, arrival time, cancellation and reschedule cutoff, weather cancellations, minors need a guardian’s consent). Values come from settings.

### 03-experience-catalog.png (experience cards with filters)
Use for `/experiences` (catalog of everything bookable, later including expeditions):
- Filter chips at the top (All, Lessons, Private, Packages, Expeditions).
- Cards with: photo, price badge on the image ("PKR 10,000 per rider"), category label, "Featured" tag, title, two-line description, meta row (duration, group size, location), and buttons "Details" and "Book now". Optional "Video" button when a video exists.
- "Why ride with us" strip (trainer, horse welfare, trail riding, beginner friendly) as a simple four-item row, not icon-in-circle clichés.
- Help call-to-action at the end: "Not sure which to choose? Send us a DM" linking to Instagram.
