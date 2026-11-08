{{--
    Exports (App\Filament\Pages\Exports, admins). Plain semantic markup:
    Filament's CSS has no utility classes.
--}}
<x-filament-panels::page>
    <x-filament::section heading="What you can export">
        <ul>
            <li><strong>Orders</strong>: reference, status, customer, product as bought, amount, refunded and net (by the date the order was created).</li>
            <li><strong>Payments</strong> and <strong>refunds</strong>: every card or bank transfer payment and every refund, with its order reference (by the date it was recorded).</li>
            <li><strong>Bookings</strong>: ticket, session, rider, status, how it was paid, price and horse (by the session date).</li>
            <li><strong>Leads</strong>: contact details, source, status and campaign tags (by the date received). This file holds personal data: keep it private and delete it when you're done.</li>
        </ul>
        <p>Dates and times are Pakistan time. Money is in PKR with two decimals (10000.00). Choose “New export” above; the file downloads straight away. Every export is recorded in the audit log, and you can run {{ \App\Filament\Pages\Exports::PER_HOUR }} an hour.</p>
    </x-filament::section>
</x-filament-panels::page>
