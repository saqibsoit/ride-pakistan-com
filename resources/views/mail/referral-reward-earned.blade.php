<x-mail::message>
# Thank you for the referral

A friend you invited has just had their first session with us, so we've added **{{ \App\Support\Money::format($amountMinor) }}** of credit to your account.

You can use it on your next booking.

<x-mail::button :url="route('account.referrals')">
See your credit
</x-mail::button>

Ride Pakistan
</x-mail::message>
