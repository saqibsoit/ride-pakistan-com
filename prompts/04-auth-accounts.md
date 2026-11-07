# Prompt 04: Customer authentication and accounts

Read `AGENTS.md` and `docs/PLAN.md` sections 6 and 7.

1. Passwordless login for customers: enter email → 6-digit one-time code by email (10-minute expiry, single use, hashed at rest, max 5 attempts, rate limited per email and per IP, Turnstile on the request form). Create the account on first successful verification. Design the code so a phone/WhatsApp OTP channel can be added later behind the same interface.
2. Profile: name, phone (E.164, validated for Pakistan and international numbers), marketing consent checkbox (unchecked by default, timestamped).
3. Riders: a customer can add riders (self and others) with name, age bracket, experience level, emergency contact (encrypted cast), notes.
4. Account area (Livewire where useful): dashboard (next session, credits left with expiry), bookings (upcoming/past), orders with status and receipts, tickets, riders, profile, "download my data" (JSON export) and "delete my account" (soft delete + anonymise after 30 days unless there are paid orders within the retention period).
5. Session security: regenerate on login, logout everywhere option, secure cookie settings.

Acceptance criteria:
- Tests for OTP expiry, reuse, brute force lockout, rate limits, account creation, data export, deletion/anonymisation, and that customers can never see other customers’ data (policy tests).
