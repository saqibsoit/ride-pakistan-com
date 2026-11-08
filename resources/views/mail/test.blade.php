<x-mail::message>
# Email is working

This is a test email from the Ride Pakistan website. If you can read it, the site can send email.

<x-mail::table>
| | |
|:--|:--|
| Sent | {{ $sentAt }} (Pakistan time) |
| Environment | {{ $environment }} |
| Mailer | {{ $mailer }} |
</x-mail::table>

Replies to this email go to the team inbox.

Ride Pakistan
</x-mail::message>
