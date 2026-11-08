<x-mail::message>
# Emails are failing to send

An email ({{ $kind }}) could not be delivered at {{ $at }} (Pakistan time), after its retries.

Error: {{ $error }}

Customers may be missing confirmations, tickets or sign-in codes. Check, in this order:

1. The Resend dashboard (Emails and Logs): is the domain mail.ride-pakistan.com still verified, and has the daily limit been reached? The free plan sends 100 emails a day.
2. `RESEND_API_KEY` in the server's `.env`. Then run `php artisan mail:test you@example.com` over SSH to see the exact error.
3. `storage/logs` for "Email delivery failed".

Failed emails stay in the failed jobs list for 30 days. Once sending works again, `php artisan queue:retry all` sends them.

You get at most one of these emails every {{ $minutes }} minutes; later failures are only logged.

Ride Pakistan
</x-mail::message>
