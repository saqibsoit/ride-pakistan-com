# Prompt 10: Security hardening

Read `AGENTS.md` and `docs/PLAN.md` section 7. Audit the whole codebase and fix gaps.

1. Security headers middleware: strict CSP with nonces (allow only what’s needed: Stripe, Turnstile, analytics when consented), HSTS, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, frame-ancestors. Add a CSP report endpoint (rate limited) logging violations.
2. Review every route: auth, policy checks, rate limits, Form Request validation, mass assignment, IDOR risks (order references and ticket codes must not leak other customers’ data).
3. Admin: MFA enforced, session timeout, password breach check, failed login alerts by email, audit log of every admin action.
4. Files: confirm private uploads unreachable, signed URL lifetimes ≤ 5 minutes.
5. Secrets and config: `APP_DEBUG=false`, no stack traces, `.env` outside web root, encryption key rotation documented.
6. Dependencies: `composer audit`, `npm audit` clean; pin versions.
7. Write `docs/SECURITY.md`: threat model (payments, bookings, PII, admin), controls mapped to OWASP ASVS L2 sections, incident response steps, backup and restore procedure.
8. Add automated security tests (headers present, CSP nonce on inline scripts, rate limits trigger, IDOR attempts return 403/404).

Acceptance criteria:
- securityheaders.com grade A+ on staging; Mozilla Observatory A+.
- All new tests pass; `docs/SECURITY.md` complete.
