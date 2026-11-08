# Open questions for launch

Business facts that `docs/PLAN.md` does not confirm. Each item lists the value the site uses today (a placeholder) and every place it appears. Please confirm or correct each one before launch; answers go into `docs/PLAN.md` (§2) and the admin Settings page, then this file is updated.

Status key:

- **Open decision**: listed in PLAN §2 as undecided; the site uses the bracketed default.
- **Unconfirmed, still shown**: low-risk wording kept on the site until confirmed.
- **Removed**: a claim that could be wrong or unsafe if untrue. It has been taken off the site and replaced with neutral wording; it can go back once confirmed.

Where a value is a setting, an admin can change it on the Settings page of the admin panel (roles and MFA arrived in milestone 3); the defaults live in `config/settings.php`.

## 1. Open decisions from PLAN §2

| # | Question | Current placeholder | Where it is used | Status |
|---|---|---|---|---|
| 1.1 | How many riders per slot? | **6** (setting `booking.riders_per_slot`) | Capacity of every seeded schedule rule (`database/seeders/ScheduleRuleSeeder.php`); "Up to 6 riders" group size on `/experiences` cards and on `/sessions/single-session`, `/sessions/8-class-package`, `/sessions/16-class-package` (key facts and price box, via `BusinessFacts::groupSize()`); "Riders per slot: up to 6" in `/llms.txt`. Seat limits from milestone 5. | Open decision |
| 1.2 | How long are seats held for a bank transfer? | **24 hours** (setting `booking.transfer_hold_hours`) | Not shown yet. Used by the transfer flow and hold expiry in milestone 7. | Open decision |
| 1.3 | Cancellation and reschedule cutoff? | **48 hours** before the slot (setting `booking.cancellation_cutoff_hours`) | Booking policy "Cancel or reschedule up to 48 hours before your slot." on `/prices` (Booking policies), on every `/sessions/{product}` page (Good to know) and in `/llms.txt`; FAQ "Can I cancel or reschedule?" (`{cutoff}` token) on `/faq`. Credit refunds on cancellation in milestone 5. | Open decision |
| 1.4 | Refund policy text? | **Empty** (setting `policies.refund`) | Hidden on `/prices` while empty. `/refund-policy` is an unpublished outline (404) in `database/seeders/PageSeeder.php`. Stripe requires a published refund policy before card payments (milestone 6). | Open decision |
| 1.5 | Minimum rider age? | **Not set** (setting `booking.min_rider_age` is empty) | Nothing shown while empty. Once set, "Minimum rider age: N." appears under Good to know on every `/sessions/{product}` page, and any policy containing `:min_age` becomes visible. | Open decision |

## 2. Equipment and what riders bring

| # | Question | Current placeholder | Where it is used | Status |
|---|---|---|---|---|
| 2.1 | Do you provide riding helmets? Are they required for everyone, in every session? Which sizes (children)? | **Nothing said** | Removed from: riding rules (`policies.riding_rules`: "A riding helmet is required at all times… We provide one"), "Your booking includes" on all product pages ("Riding helmet if you do not have your own"), FAQ "What should I wear?" ("We provide a riding helmet"), FAQ "Is horse riding safe for beginners?" ("You wear a helmet"), draft guide outline "What to wear…". | Removed |
| 2.2 | What other kit do you provide (tack, body protectors, boots, gloves)? | **Nothing said** | Removed from the draft guide outline "What to wear…" ("What we provide: helmet and tack"). "Grooming and tacking up with your trainer" (session content from PLAN §2) is still listed. | Removed |
| 2.3 | What should riders wear and bring? | **Full-length trousers; closed shoes or boots with a small heel** (from the reference page FAQ) | "What to bring" on every `/sessions/{product}` page; riding rule "Wear full-length trousers and closed shoes or boots with a small heel." on `/prices` and in `/llms.txt`; FAQ "What should I wear?" (also: avoid loose scarves); home page "How to book" step 4 ("Come in long trousers and closed shoes"). | Unconfirmed, still shown |
| 2.4 | Should riders bring water, sunscreen or warm layers? | **Nothing said** | Removed from "What to bring" ("A water bottle", "Sunscreen in summer, a warm layer in winter"). | Removed |

## 3. Policies and on-the-day rules

| # | Question | Current placeholder | Where it is used | Status |
|---|---|---|---|---|
| 3.1 | How early should riders arrive? | **Nothing said** | Removed from the riding rules ("Arrive 15 minutes before your slot") and the home page "How to book" step 4 ("Arrive 15 minutes early"). | Removed |
| 3.2 | What happens in bad weather (who decides, rescheduling, cost)? | **Nothing said** | Removed from booking policies ("If weather makes riding unsafe, we move your session to another slot at no cost") and the FAQ "Can I cancel or reschedule?". | Removed |
| 3.3 | Rules for minors: is a parent or guardian's consent needed? Must they attend? From what age? | **Nothing said** | Removed booking policy "Riders under 18 need a parent or guardian's consent." Linked to 1.5 (minimum age). | Removed |
| 3.4 | Is there photography or video at sessions, and how do riders opt out? | **Nothing said** | Removed booking policy "We sometimes take photos and video…". | Removed |
| 3.5 | Is transport provided or available? | **Nothing said** | Removed booking policy "Transport is not provided." (The location pin sentence stays; it is in PLAN §2.) | Removed |
| 3.6 | Should the site make any safety statement for beginners? | **None** | Removed FAQ "Is horse riding safe for beginners?" (claimed helmets, calm horses and that the trainer stays with riders "the whole time"). PLAN §8 lists a future guide on this question; it needs your input before it is written. | Removed |
| 3.7 | "Follow your trainer's instructions around the horses at all times." | **Shown as a riding rule** | `/prices` (Riding rules). Neutral wording added in place of the removed rules. | Unconfirmed, still shown |

## 4. Products and prices

| # | Question | Current placeholder | Where it is used | Status |
|---|---|---|---|---|
| 4.1 | Are package prices per rider, and can one package be shared between riders? | **Decided in milestone 5:** one pack per order, owned by the account, priced as listed; any of the customer's riders can use its credits (one credit per rider per session). | `/book`, `/account/credits`, package pages. Package copy on `/prices` still doesn't say it can be shared; worth adding once you're happy with the rule. | Decided |
| 4.2 | Is "2 sessions per week" a rule or a suggestion for packages? | **Shown as guidance** ("two a week") | Package short descriptions on `/experiences`, `/prices` and the package pages; note on `/prices`: "plan around two sessions a week". If it is a rule, milestone 5 must enforce it. | Unconfirmed, still shown |
| 4.3 | Maximum group size for a private session? | **Up to the slot capacity** (6 by default), price per rider; the whole slot is blocked either way. | `/book`, `/sessions/private-session` key facts and price box ("Just your group"); `/experiences` card. | Confirm |
| 4.4 | What do packages lead to? | **Neutral.** Package copy now lists the sessions and validity only. | Replaced on the 8 and 16-class products (seeded in `database/seeders/ProductSeeder.php`): "from your first time touching a horse to riding the trail with confidence", "longer trails, more independence, real horsemanship", "gets you ready for an expedition". | Removed |
| 4.5 | "8 and 16-class packages take beginners to confident trail riders" / "a package takes you from complete beginner to a confident trail rider" | **Shown** (adapted from your reference page) | Home page answer block and "Sessions and prices" introduction. | Unconfirmed, still shown |
| 4.6 | Which product should be featured? | **8-class package** | Featured badge on `/experiences` and the product page; highlighted row on the home page and `/prices`. The "Most popular" label on `/prices` was removed (no data behind it). | Unconfirmed, still shown |
| 4.7 | Product names | **Single session, Private session, 8-class package, 16-class package** (PLAN §2) | Everywhere. The reference page used "Trial session" and "Beginner to trail rider"; PLAN names were used. | Confirm naming |

## 5. Trainer, contact and other copy

| # | Question | Current placeholder | Where it is used | Status |
|---|---|---|---|---|
| 5.1 | Is Omar happy with his biography being published (polo family; father captained the Pakistan Army polo team)? | **Shown** (from your reference page) | `/trainer/omar-saleem` (CMS page, Person schema), home page trainer section (shortened). | Unconfirmed, still shown |
| 5.2 | Does Omar lead every session and stay with riders throughout? | **"Leads your session and stays with you throughout"** (the reference page FAQ says "your trainer stays with you throughout") | `/experiences` "Why ride with us"; FAQ "I've never been near a horse". Replaced "has ridden all his life". | Unconfirmed, still shown |
| 5.3 | How quickly do you reply to messages? | **No promise** ("we'll get back to you") | Removed "within a day" from `/contact` (answer block, success message, meta description). | Removed |
| 5.4 | Contact email: `hello@ridepakistan.co` (from the reference page) while the site is `ride-pakistan.com`. Which address should be public? | **hello@ridepakistan.co** (setting `contact.email`, from `SITE_EMAIL`) | Footer, `/contact`, Organization and location JSON-LD, `/llms.txt`. | Confirm |
| 5.5 | Instagram profile and DM links | **instagram.com/ridepakistan, ig.me/m/ridepakistan** (reference page) | Header (mobile menu), footer, `/contact`, `/book`, `/experiences`, product pages, JSON-LD `sameAs`. | Confirm |
| 5.6 | Expeditions: duration and what the expeditions site covers | **Neutral**: "a Ride Pakistan expedition through the northern valleys" (reference page) | Removed "multi-day rides" and "routes, dates and prices are on ridepakistan.co" from `/expeditions` and the `/experiences` card. | Removed |
| 5.7 | "Most riders…" claims | **None** | Removed "Most riders start having never touched a horse" (`/experiences`) and "Most riders finish their first session already looking forward to the next" (single session). | Removed |
| 5.8 | Lesson product cards use expedition photos (`ride.jpg`: rider crossing a mountain river; `gallop.jpg`: rider galloping below mountains; `camp.jpg`). Lessons take place in Islamabad. OK, or do you have photos from the Islamabad sessions? | **Expedition photos** | `/experiences`, product pages, `/prices` JSON-LD images, home page. The home page keeps the reference caption "Photographed on a Ride Pakistan expedition". | Confirm |
| 5.9 | Which AI crawlers to welcome in robots.txt? | **GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, Claude-SearchBot, Claude-User, PerplexityBot, Perplexity-User, Google-Extended, Applebot-Extended** (setting `seo.ai_crawlers`) | `/robots.txt` in production. PLAN §8 says "reputable search and AI crawlers"; the list itself is my choice. | Confirm |

## 6. Booking system and admin (milestone 3)

Placeholders chosen while building the data model and admin panel. None of these are shown on public pages yet.

| # | Question | Current placeholder | Where it is used | Status |
|---|---|---|---|---|
| 6.1 | Which age groups do you need to know about for each rider? | **Under 12, 12 to 15, 16 to 17, 18 or over** (`App\Enums\AgeBracket`) | Rider records in the admin (Customers & riders); booking form from milestone 5. Linked to 1.5 and 3.3. | Unconfirmed |
| 6.2 | How should riders describe their experience? | **Never ridden, Ridden a few times, Confident rider** (`App\Enums\ExperienceLevel`) | Rider records in the admin; booking form from milestone 5. | Unconfirmed |
| 6.3 | How far ahead can people book? | **8 weeks** (setting `booking.slot_horizon_weeks`, from the milestone 3 prompt) | Slot generation (`slots:generate`, daily and after every schedule rule change). | Confirm |
| 6.4 | What may staff (non-admin) accounts do? | **Read slots and bookings only**; check-in from milestone 8 | Admin panel permissions (`app/Policies`). Admins can do everything. | Confirm |
| 6.5 | Who should have admin access, and who staff access? | **Nobody yet.** Accounts are created with `php artisan admin:create`; each must set up an authenticator app at first sign-in. | Admin panel. | To do before launch |

## 7. Content still to write

| # | Item | Current state | Where |
|---|---|---|---|
| 7.1 | Privacy policy, terms and conditions | Unpublished headings-only outlines; pages return 404 and are hidden from the footer and sitemap | `/privacy`, `/terms` (`database/seeders/PageSeeder.php`) |
| 7.2 | Refund policy | See 1.4 | `/refund-policy` |
| 7.3 | Guides | Three drafts with outlines only; `/guides` shows an empty state and is `noindex` | `database/seeders/PostSeeder.php` |

## 8. Customer accounts (milestone 4)

Defaults chosen while building sign-in and the account area. Settings can be changed on the admin Settings page ("Privacy and data retention").

| # | Question | Current placeholder | Where it is used | Status |
|---|---|---|---|---|
| 8.1 | How long must paid order records (with the customer's name and email) be kept? Please check with your accountant. | **6 years** after the last payment (setting `privacy.order_retention_years`) | Account deletion: an account with a paid order inside this period stays locked and is anonymised once it has passed. Stated on `/account/privacy`. | Confirm |
| 8.2 | How long can a customer undo "delete my account"? | **30 days** (setting `privacy.deletion_grace_days`) | `/account/privacy`, the sign-in page after deletion, the "Keep your account?" page. | Confirm |
| 8.3 | What happens to unused package credits when a customer deletes their account? | **Forfeited**, after an explicit tick box | `/account/privacy`. No refund is promised. | Confirm |
| 8.4 | Marketing consent wording | **"Send me news and offers. Occasional emails about new sessions and expeditions. Unsubscribe any time."** Unticked by default. | `/account/profile`. No marketing emails are sent yet. | Confirm |
| 8.5 | Sign-in code limits | **6 digits, 10 minutes, 5 tries per code; 3 codes per 10 minutes and 10 a day per email; 10 requests an hour per network** (`config/login.php`) | `/login`. Changing them needs a deploy. | Confirm |
| 8.6 | Customers change their email by contacting you (no self-service yet). OK? | **"To change it, contact us"** | `/account/profile`. | Confirm |

## 9. Booking engine (milestone 5)

| # | Question | Current placeholder | Where it is used | Status |
|---|---|---|---|---|
| 9.1 | Until when can a slot be booked? | **Until it starts** (setting `booking.min_notice_hours` = 0) | `/book`, `/schedule`, product pages. Raise it on the Settings page to stop last-minute bookings. | Confirm |
| 9.2 | How long are seats held while a customer pays by card? | **30 minutes** (setting `booking.card_hold_minutes`, from PLAN §3.1). Starting card payment extends the hold to at least 31 minutes from that moment, because a Stripe session stays open for at least 30. | `/book` review ("We hold your seats for 30 minutes"), `/checkout/{reference}`. | Confirmed in PLAN |
| 9.3 | Can customers change bookings they paid for directly (single or private)? | **No.** Only credit bookings can be moved or cancelled by the customer; paid bookings show "contact us" and an admin changes them. | `/account/bookings`. Linked to 1.3 (cutoff) and 1.4 (refund policy). | Decided (milestone 5) |
| 9.4 | Should the schedule have a trainer filter? | **Hidden.** Slots don't record a trainer and there is one trainer, so the filter appears only once there are more. | `/schedule`. | Confirm |
| 9.5 | Free ("no charge") admin bookings: who may give them, and should they be limited? | **Any admin, with a required reason** (logged). | Admin → Bookings → Manual booking. | Confirm |

## 10. Card payments (milestone 6)

| # | Question | Current placeholder | Where it is used | Status |
|---|---|---|---|---|
| 10.1 | What should card statements show? | Account descriptor (set in the Stripe dashboard under Settings → Public details) + suffix **`RIDE PAKISTAN`** (`STRIPE_STATEMENT_DESCRIPTOR_SUFFIX`). Stripe allows 22 characters in total, so "Ride Pakistan by Destination Pakistan" can't fit; it appears on the Stripe checkout page, the checkout page and the receipt instead. Please check the dashboard prefix (e.g. `DESTPAK`). | Every card charge. | Confirm |
| 10.2 | Should Stripe also send its own receipt email? | **Our "Payment received" email only.** Stripe's receipts are an account setting (Settings → Customer emails). | Customer inbox. | Confirm |
| 10.3 | Refund policy text (1.4) is still empty. Stripe expects a published refund policy before live card payments. | **Empty** | `/refund-policy` (unpublished). Blocks going live with card payments. | Open decision |
| 10.4 | When a refund is made, what happens to bookings and credits? | **Full refund:** upcoming bookings cancelled, unused credits voided. **Partial:** the admin chooses. Refunds made in the Stripe dashboard change the money only and flag the order for review. | Admin → Orders → Refund. | Decided (milestone 6) |

## Confirmed in PLAN (no action needed)

For reference, these facts are used as stated in `docs/PLAN.md`: prices and validity (single PKR 10,000 and private PKR 15,000 per rider; 8-class PKR 60,000 for 6 weeks; 16-class PKR 100,000 for 10 weeks, from confirmation); Saturday and Sunday, 08:00–10:00 and 15:00–17:00 Asia/Karachi, shifting with the seasons; session content (bring in, groom, tack up, ride mostly trail with arena work on request, untack, massage and stretch); trainer Omar Saleem; location shown as "Islamabad" with the exact pin sent after confirmation; no experience needed; private sessions block the whole slot; card and bank-transfer payment; expired credits cannot be used; "Selling fast" at 2 seats or fewer (`docs/reference/DESIGN-REFERENCES.md`).
