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

    <x-filament::section heading="Weather alerts" :collapsible="true">
        <ol>
            <li><strong>Switch it on</strong> (admins, System → Settings → Weather alerts): enter the venue latitude and longitude (private, never shown on the site), check the thresholds and choose who gets alerts (admins, staff, and trainers for their own sessions).</li>
            <li><strong>Forecasts</strong> are fetched at 06:00 and 18:00 Pakistan time for sessions in the next 48 hours. If they fail, the site retries every hour, and every admin gets one email after 12 hours of failures.</li>
            <li><strong>Levels</strong>: “Watch” means one threshold is crossed; “Warning” means a thunderstorm or two thresholds. The team is emailed when a session’s level changes, and again with “all clear” when it improves. The run sheet and the Slots list show the level.</li>
            <li><strong>Telling riders</strong>: open the slot and choose “Weather notice”. “Weather update” and “Session moved” email every customer with a confirmed booking, once each; the message is yours to edit. Moving riders is still done booking by booking. “Session cancelled” cancels the slot as usual (credits back, paid bookings flagged for a refund or move) and the cancellation email shows your message.</li>
        </ol>
        <p>The templates are neutral on purpose until the bad-weather policy is decided. Edit them under Settings → Weather alerts.</p>
    </x-filament::section>

    <x-filament::section heading="Gift vouchers, promo codes and referrals" :collapsible="true">
        <p>Everything here is under <strong>Sales &amp; promotions</strong> and is for admins only. Customers use them on the checkout page (see “Discounts at checkout and refunds”).</p>
        <ol>
            <li><strong>Gift vouchers</strong>: customers buy them on /gift-vouchers for a session or an amount, by card or bank transfer. A voucher exists only once the payment is confirmed, and the recipient gets an email with the code and a printable voucher (straight away or at 9 am on the chosen day). Bank transfers for vouchers are checked under <strong>Verify voucher transfers</strong>, like order transfers.</li>
            <li><strong>Issue voucher</strong> (Gift vouchers list) gives one without a purchase, for example after a rain cancellation. Give the reason; it's kept in the history. <strong>Void</strong> cancels what's left (it doesn't refund anyone), <strong>Extend expiry</strong> moves the date (an expired voucher gets its balance back), and you can change the recipient until the email has gone.</li>
            <li><strong>Promo codes</strong>: percentage or a fixed amount, optional products (none means all), start and end, total and per-customer limits, a minimum order and "first paid booking only". One code per order; it never combines with another code, only with a gift voucher. Once a code has been used its code, discount and products are fixed; archive it and make a new one instead.</li>
            <li><strong>Referrals</strong>: every customer has a personal link under Account → Refer a friend. When a new customer who signed up with it has paid for and attended a session, the referrer gets account credit (amount in Settings → Referrals). Same email, same phone or a self-referral is rejected automatically with the reason; you can reject or reinstate a referral by hand. Change a customer's credit on their page under <strong>Account credit</strong>.</li>
        </ol>
    </x-filament::section>

    <x-filament::section heading="Discounts at checkout and refunds" :collapsible="true">
        <ol>
            <li><strong>At checkout</strong> the customer types a gift voucher or promo code, and can use their account credit. The order shows the price, each discount and what is left to pay; card or bank transfer is for that amount only. A friend who signed up through a referral link gets the welcome discount by itself on their first paid booking (a promo code replaces it).</li>
            <li><strong>Nothing left to pay?</strong> When a voucher and credit cover everything, the customer presses “Confirm booking” and gets their tickets as usual. The order shows the method “Gift voucher / account credit”.</li>
            <li><strong>Unpaid orders</strong> that run out, are cancelled or rejected give the voucher balance, the credit and the promo use back by themselves.</li>
            <li><strong>Refunds</strong> by card are limited to what the customer paid by card. The same share of any voucher and credit comes back with it (refund half, half comes back; the last refund brings back the rest). A voucher that has expired or been voided since gets its share as account credit instead. For an order paid only by voucher and credit, use “Cancel and restore balances”. Promo and referral discounts stay used.</li>
            <li><strong>Reports</strong>: revenue counts money received plus gift voucher balance spent (vouchers count when they are used, not when they are sold). The orders export has columns for the subtotal and each kind of discount.</li>
        </ol>
    </x-filament::section>

    <x-filament::section heading="Waitlists, rider progress and reviews" :collapsible="true">
        <ol>
            <li><strong>Waitlist</strong>: when a group session is full, customers can join its waitlist from the schedule. When seats free up, the first person in line whose group fits gets an email and the seats are held for them for the time set under Settings → Booking rules (“Waitlist offer”). If they don’t book in time, the offer moves to the next person. See and manage it on the slot page, tab <strong>Waitlist</strong>: “Offer free seats now” runs the same check straight away (it also runs every minute); “Remove” takes someone off with a reason.</li>
            <li><strong>Rider progress</strong>: after a session the trainer ticks the skills a rider showed on the run sheet and writes a short note for the family. Customers see both under Account → Progress, with a suggested next step. Edit the checklist under Customers → Rider skills; switch a skill off instead of deleting it once riders have it.</li>
            <li><strong>Reviews</strong>: the thank-you email links to a review form (no sign-in needed). New reviews wait under Content → Reviews. <strong>Approve</strong> to show one on the homepage and its product page, <strong>Hide</strong> with a reason, or <strong>Reply</strong> publicly. Star ratings appear in Google results only for a product with at least 5 approved reviews.</li>
        </ol>
        <p>Calendar files: booking emails carry an .ics file and a Google Calendar link; moving or cancelling a booking sends an updated or cancelling file.</p>
    </x-filament::section>
</x-filament-panels::page>
