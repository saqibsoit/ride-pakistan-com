# Prompt 10e: Gift vouchers, promo codes and referrals

Read `CLAUDE.md`, `docs/PLAN.md` (section 3 payments) and `docs/reference/DESIGN-REFERENCES.md`. Start in plan mode.

1. Gift vouchers: customers buy a voucher for any active product or a PKR amount, pay by card or bank transfer (same flows and verification as orders), add the recipient’s name, email and a message, and choose send now or on a date. The recipient gets a branded email with a unique code and a printable voucher page. Validity 12 months (setting). Redeeming at checkout covers all or part of an order; any remainder stays on the voucher. Vouchers are only issued after payment is confirmed.
2. Promo codes (admin): percentage or fixed PKR amount, applies to selected products, start and end dates, total and per-customer usage limits, minimum order, first-booking-only option. One code per order. Never stacks with another promo; can combine with a gift voucher.
3. Referrals: each customer gets a personal referral link. A new customer using it gets a discount on their first paid booking; the referrer gets account credit (PKR amount, setting) once that booking is paid and attended. Basic abuse checks (same email, same phone, self-referral).
4. All discounts are calculated on the server, stored on the order as separate lines, included correctly in Stripe Checkout amounts, in refunds (proportional) and in exports and revenue reports.
5. Admin screens for vouchers (issued, redeemed, balance, expiry), promo codes (usage) and referrals.

Acceptance: tests for amount calculation, limits, expiry, stacking rules, voucher balance after partial use, refunds with discounts, referral abuse checks, Stripe amount matching. Work on a feature branch.
