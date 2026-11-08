{{--
    Admin guide (App\Filament\Pages\AdminGuide). Read-only. Plain semantic
    markup: Filament's CSS has no utility classes.
--}}
@php
    $link = fn (?string $url, string $label): string => $url ? '<a href="'.e($url).'" class="fi-link">'.e($label).'</a>' : e($label);
@endphp
<x-filament-panels::page>
    <x-filament::section heading="Add a new package" :collapsible="true">
        <ol>
            <li>Open {!! $link($newProductUrl, 'Products → New product') !!}. Choose type <strong>Package</strong> and category <strong>Packages</strong>.</li>
            <li>Enter the <strong>price for the whole package</strong> in rupees, the <strong>number of sessions</strong> and the <strong>validity in days</strong> (counted from the day the payment is confirmed). The per-session price appears under the price and on the site automatically.</li>
            <li>Fill in the content tab (short description, who it’s for, what to bring, what’s included) and the cover photo with alt text. The page address (slug) is permanent once shared, so choose it carefully.</li>
            <li>Leave <strong>On sale</strong> off while you check the page, then switch it on. The prices page, home page, structured data and llms.txt update straight away.</li>
        </ol>
        <p><strong>Changing a price, the number of sessions or the validity later</strong> only affects new orders. Every order keeps the name, price, sessions and validity it was bought with, including unpaid bank transfers approved later. Credit packs never change.</p>
        <p>The type (single, private or package) is fixed once a product has orders. To sell something different, archive it and create a new product.</p>
    </x-filament::section>

    <x-filament::section heading="Change seasonal timings" :collapsible="true">
        <ol>
            <li>Open {!! $link($rulesUrl, 'Schedule rules') !!}. Each rule is one weekly session: a day, start and end time (Pakistan time) and number of riders.</li>
            <li>For new summer times, set an <strong>Until date</strong> on each current rule (e.g. 31 March) and create new rules with a <strong>From date</strong> (e.g. 1 April). Old and new times then switch over on their own.</li>
            <li>Before saving, read the <strong>Preview</strong> at the bottom of the form: the slots that will be created, changed or removed in the coming weeks.</li>
            <li><strong>Booked slots never move.</strong> Slots with bookings, holds or unpaid orders are listed under “Kept as they are”. Contact those riders, then move them (reschedule) or cancel the slot.</li>
        </ol>
        <p>Slots are created {{ $admin ? 'as far ahead as the “Create bookable slots this many weeks ahead” setting' : 'a few weeks ahead' }}.@if ($settingsUrl) Change it in {!! $link($settingsUrl, 'Settings') !!}.@endif</p>
    </x-filament::section>

    <x-filament::section heading="Add a one-off session" :collapsible="true">
        <ol>
            <li>Open {!! $link($slotsUrl, 'Slots') !!} and choose <strong>Add manual slot</strong>. Any date works, weekdays included.</li>
            <li>Set the start, end and number of riders. Turn on <strong>Private sessions only</strong> for a slot only private bookings can take; it shows as “Private only” on the schedule.</li>
            <li>The schedule rules never change or remove a manual slot. Use <strong>Edit slot</strong> to change it: the time only while nobody has booked, and the riders never below the seats already taken.</li>
        </ol>
        <p><strong>Close</strong> stops new bookings but keeps existing ones (reopen any time). <strong>Cancel</strong> is final: every booking is cancelled, package credits go back, paid bookings are flagged “Needs review” for you to refund or move, and customers get an email.</p>
    </x-filament::section>

    <x-filament::section heading="Hide, archive or delete a product" :collapsible="true">
        <ul>
            <li><strong>Hide</strong> (switch “On sale” off): gone from the site and the booking flow, still in the list. Use it for a pause.</li>
            <li><strong>Archive</strong>: for a product you won’t sell again. It leaves the site and the product list (filter “Archived” to see it). Orders, tickets and credit packs keep working. You can restore it; it comes back switched off.</li>
            <li><strong>Delete</strong>: only for a product nobody ever ordered (e.g. created by mistake). Products with orders can only be archived.</li>
        </ul>
        <p>Every change here is recorded in the audit log with who made it, and the public pages refresh straight away.@if ($productsUrl) Manage them under {!! $link($productsUrl, 'Products') !!}.@endif</p>
    </x-filament::section>

    <x-filament::section heading="Answering Instagram DMs" :collapsible="true">
        <ol>
            <li>Add the person as a lead (Leads → Add lead) with their Instagram handle.</li>
            <li>On the lead (or its card on the board) open <strong>Quick replies</strong> and press <strong>Copy</strong> on the reply you need, then paste it into Instagram. Prices, times and links are always today’s.</li>
            <li>Links in a copied reply carry the lead’s tracking reference. If they book and pay from it in the same visit, the lead moves to <strong>Booked</strong> by itself.</li>
            <li>Move the lead to <strong>Contacted</strong>: a follow-up date is suggested (change it if you like). Leads due show on the dashboard and in the 09:00 email.</li>
        </ol>
        <p>Admins edit the wording under Leads → Reply templates. Use tokens such as <code>{price:single-session}</code>, <code>{schedule}</code> and <code>{booking_link}</code> instead of typing prices or times, so the replies never go out of date.</p>
    </x-filament::section>

    <x-filament::section heading="Horses, trainers and run sheets" :collapsible="true">
        <ol>
            <li><strong>Horses</strong> (admins, Schedule → Horses): set the levels each horse suits, the most sessions a day, and rest or vet days under “Unavailable days”. Retire a horse that won’t work again; it stays in the history of the sessions it carried.</li>
            <li><strong>Trainers</strong>: create the account with <code>php artisan admin:create</code> (role Trainer). They set up an authenticator app at first sign-in, like staff. Pick the trainer on each schedule rule; new sessions take it, and you can change it per session from the slot’s actions (“Trainer”). A trainer sees only their own sessions and riders, never payments, leads or settings.</li>
            <li><strong>Run sheet</strong> (slot actions → Run sheet, or {{ url('/team/sessions') }} on a phone): every rider with level, notes, emergency contact (tap to call), horse, payment status and check-in buttons. There is a printable version.</li>
            <li><strong>Assigning horses</strong>: a horse on a rest or vet day, retired, already riding at that time, or at its daily limit can’t be chosen. A horse not listed for the rider’s level only shows a warning.</li>
        </ol>
        <p>Trainers write a short session note for each rider; it appears in the rider’s history. Notes are stored encrypted.</p>
    </x-filament::section>
</x-filament-panels::page>
