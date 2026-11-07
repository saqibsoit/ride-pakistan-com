# Prompt 09: Leads CRM and tracking

Read `AGENTS.md` and `docs/PLAN.md`.

1. Lead capture: the `/contact` form and an "Ask a question" form on `/prices` (name, phone, email, interest: single/private/package/expedition, preferred date, message). Turnstile + honeypot + rate limit. Store UTM parameters, `fbclid`, `gclid`, landing URL, referrer and first-touch timestamp (captured on first visit in a first-party cookie, consent-aware).
2. Manual lead entry in Filament for Instagram DMs (source = instagram_dm, with optional Instagram handle).
3. Leads in Filament: list with filters (status, source, date) and a kanban board (new → contacted → qualified → booked → lost with reason). Notes, assignee, next follow-up date, "convert to customer" which links or creates the user. Automatic status "booked" when a linked user completes a paid order.
4. Reporting widget: leads by source per week, conversion rate to paid orders, revenue by source.
5. Consent banner (only for non-essential tracking). If consent is given: Meta Pixel and GA4 with events `Lead`, `InitiateCheckout`, `Purchase` (value in PKR). Add server-side Meta Conversions API for `Lead` and `Purchase` with event deduplication IDs. All scripts nonce-based and listed in the CSP.

Acceptance criteria:
- Tests: lead creation with UTM capture, spam protections, conversion linking, no tracking scripts rendered without consent, CAPI payload shape (mocked HTTP).
