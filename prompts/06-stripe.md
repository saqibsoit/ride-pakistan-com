# Prompt 06: Stripe Checkout and webhooks (PKR)

Read `AGENTS.md` and `docs/PLAN.md` section 3.1. The Stripe account belongs to Destination Pakistan.

1. Config: `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, test mode on local/staging. First, add an artisan command `stripe:check` that creates a test Checkout Session in PKR and prints the result, so we can confirm the account supports PKR before going further. Stop and report if it fails.
2. `CreateStripeCheckout` action: Checkout Session (mode `payment`, currency `pkr`, line items from the order with server-side amounts, `client_reference_id`, metadata with order reference, customer email, success and cancel URLs to `/checkout/{reference}/status`, expiry aligned with the card hold window, idempotency key per order).
3. `/checkout/{reference}`: shows the order summary and two options: "Pay by card" and "Pay by bank transfer". Clear note that some Pakistani cards block international online payments and bank transfer is the alternative.
4. `POST /webhooks/stripe`: verify signature, store event id in `stripe_events` (unique) for idempotency, re-check amount and currency against the order, then:
   - `checkout.session.completed` (paid) and `async_payment_succeeded` → `FulfilOrder`
   - `async_payment_failed`, `expired` → mark order failed/expired, release holds, offer bank transfer
   - `charge.refunded` → record refund, update order status
5. Status page polls (Livewire, every 3 s, max 2 min) until paid or failed; never fulfils on its own.
6. Admin: refund from Filament (full or partial) via Stripe API, with reason and audit log; refunds of credit packs reduce or void remaining credits per rules.
7. Statement descriptor and receipt emails mention "Ride Pakistan by Destination Pakistan" so customers recognise the charge.

Acceptance criteria:
- Tests with Stripe fixtures/mocks: valid signature, invalid signature (400), duplicate event (no double fulfilment), amount mismatch (no fulfilment + alert), expired session releases holds, refund flow.
- Manual test with Stripe CLI documented in README.
