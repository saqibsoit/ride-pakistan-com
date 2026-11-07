# Agent rules for this repository

Read `docs/PLAN.md` before every task. It is the source of truth for business rules, stack and standards.

## How to work
- Work on one milestone prompt at a time. Do not start the next milestone unless asked.
- Before writing code, list the files you will create or change and any assumptions. If a business rule is unclear, stop and ask instead of guessing.
- Small, reviewable commits with conventional commit messages (`feat:`, `fix:`, `chore:`, `test:`).
- Finish every task by running: `php artisan test`, `vendor/bin/phpstan analyse`, `vendor/bin/pint --test`. Report the results.
- Update `CHANGELOG.md` and, if a rule changed, `docs/PLAN.md`.

## Code standards
- Latest stable Laravel conventions. Strict types in every PHP file (`declare(strict_types=1);`).
- Business logic lives in single-purpose Action classes (`app/Actions`), not controllers or Livewire components.
- Money: integer minor units (`amount_minor`), PKR. Never floats. Format only in views.
- All dates stored in UTC, displayed in Asia/Karachi.
- Enums for statuses (PHP backed enums). State changes go through one method that validates the transition and writes the audit log.
- Multi-step writes in DB transactions. Seat checks use `lockForUpdate()` on the slot.
- Validation in Form Requests or Livewire rules. Authorization in Policies. `$fillable` on every model.
- No secrets, IBANs, keys or prices hard-coded; use settings/config.
- Every feature ships with Pest tests (feature tests for flows, unit tests for Actions). Payments, webhooks, holds and credits need edge-case tests.

## Frontend standards
- Blade + Tailwind + Alpine; Livewire only where interaction is needed.
- Design tokens from `resources/css/tokens.css` only. No ad-hoc colours or font sizes.
- Semantic HTML, WCAG 2.2 AA, visible focus, labels on every input, `prefers-reduced-motion` respected.
- Images through the responsive image component (AVIF/WebP, width/height, lazy below the fold).
- No third-party scripts without consent handling and a CSP entry.

## Never
- Never fulfil an order outside `FulfilOrder` (called by the Stripe webhook handler or the admin approval).
- Never expose transfer screenshots publicly.
- Never disable CSRF, CSP or rate limits to “make something work”.
- Never commit `.env`, keys or customer data.
