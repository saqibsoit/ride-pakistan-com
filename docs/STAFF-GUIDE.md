# Ride Pakistan staff guide

For the team at the stables and on the phones: admins, staff and trainers. No technical knowledge needed. Button and menu names are written exactly as they appear on screen, in **bold**.

Times on every screen are **Pakistan time**. Money is in **PKR**.

If something here doesn't match what you see, or something looks wrong with a payment, stop and ask an admin before doing anything else.

---

## 1. Who can do what

| | Admin | Staff | Trainer |
|---|---|---|---|
| Run sheets, check-in, horses on riders, session notes | Yes | Yes | Only their own sessions |
| See slots and bookings | Yes | Yes (read only) | Only their own sessions |
| Leads, quick replies, follow-ups | Yes | Yes | No |
| Bank transfers, refunds, orders | Yes | **No** | No |
| Gift vouchers, promo codes, referrals | Yes | **No** | No |
| Slots, schedule rules, horses, settings | Yes | No | No |

**Signing in.** Use the admin panel address you were given (it is private, so don't share it). Every team account needs an **authenticator app** on your phone (Google Authenticator, Microsoft Authenticator or similar). The first time you sign in, you scan a code with it; after that you type the 6-digit number it shows each time. Keep your recovery codes somewhere safe.

- The team never signs in with the emailed codes customers use. Always use the admin panel.
- You are signed out after a while without activity. Just sign in again.
- Several wrong passwords or codes in a row send a warning email to you and the admins. If that wasn't you, tell an admin straight away.

Trainers see **My sessions** on their home page and nothing else.

---

## 2. Verifying bank transfers (admins only)

Customers who pay by bank transfer upload a screenshot or PDF of their transfer. Their seats stay held while you check it. **An uploaded transfer never expires on its own**, so a slow check never loses someone their booking.

1. Open **Verify transfers** (under **Bookings**). The number next to it is how many are waiting. Soonest deadline first.
2. For each row, check: customer, **Expected** amount, the order reference (starts `RP-`), **Transaction ID** if they gave one, the session and riders.
3. Press **Proof** to see the screenshot. It opens in a private window that works for 5 minutes. Every view is recorded.
4. Open your online banking and find the money:
   - the **exact amount** shown under Expected, and
   - the **reference** (`RP-…`) in the payment description.
5. Then choose one:
   - **Approve**: only once the money is really in the account. Press **Confirm**. The booking is confirmed, tickets go to the customer by email, and package credits are added.
   - **Reject**: the money isn't there, the amount is wrong, or the screenshot isn't real. Write the **reason**: the customer receives it in an email, so be clear and polite (for example: "We received PKR 1,000, not PKR 10,000. Please send the remaining PKR 9,000 or contact us."). The seats are released.
   - **Extend**: the customer needs more time to pay (no upload yet). Pick the new deadline and give a reason. It can't go past the session start.
6. "Overdue" means the deadline has passed but a proof was uploaded. Check it as normal.

Good to know:
- If **Approve** says "Not approved", the seats have gone in the meantime. Reject the transfer and refund the money by bank (section 9), or contact the customer and move them to another session.
- Never forward, download to your phone, or share transfer screenshots. They contain the customer's bank details. Screenshots are deleted automatically some time after they are reviewed; the payment record stays.
- Gift voucher purchases paid by transfer are checked the same way under **Verify voucher transfers**.
- If transfers wait more than 12 hours, every admin gets a reminder email.

---

## 3. Managing slots and seasonal timings (admins)

The weekly sessions (currently Saturday and Sunday, 08:00–10:00 and 15:00–17:00) come from **schedule rules**. The site creates bookable **slots** from them for the coming weeks (the number of weeks is in **Settings**).

**Changing times for a new season**

1. Open **Schedule Rules** (under **Schedule**). Each rule is one weekly session: day, start and end time, number of riders and trainer.
2. On each current rule, set an **Until** date (the last day of the old times, for example 31 March).
3. Create new rules with a **From** date (for example 1 April) and the new times.
4. Before saving, read the **preview** at the bottom of the form: it lists the slots that will be created, changed or removed in the coming weeks.
5. **Booked slots never move.** Anything with a booking, a hold or an unpaid order is listed under "Kept as they are". Contact those riders and move them, or cancel that slot (below).

**Closing or cancelling a slot** (on **Slots**, open the slot):
- **Close**: no new bookings, existing ones stay. You can **Reopen** any time.
- **Cancel slot**: final. Every booking is cancelled and customers get one email. Package credits go back automatically. Bookings that were paid directly (single or private) are marked **Needs review**, and you then refund or move them yourself (section 9). Write the reason; customers see it.

---

## 4. Adding one-off and private sessions (admins)

**A one-off session** (a weekday, a holiday, an extra time):
1. **Slots** → **Add manual slot**.
2. Set the start, end and number of riders.
3. Turn on **Private sessions only** if only a private group may book it. It shows "Private only" on the public schedule.
4. Save. Schedule rules never change or remove a manual slot.

To change it later, use **Edit slot**: the time only while nobody has booked, and the number of riders never below the seats already taken.

**Private sessions.** A private booking takes the whole slot, whatever the number of riders, and is priced per rider. Customers can book any slot nobody else has booked yet. You don't need to do anything special.

**Booking someone in yourself:** **Bookings** → **Manual booking**. Use one of the customer's package credits, or "no charge" with a reason (for example a make-up session). A manual booking never creates a payment, so take money only through the normal checkout.

---

## 5. Check-in and the run sheet (staff, trainers, admins)

Use your phone. Sign in to the admin panel first.

**The run sheet**
1. Open **/team/sessions** on the site (your admin will give you the bookmark), or open a slot in the panel and choose **Run sheet**.
2. You see today's sessions and the next two weeks, with riders booked against places. Tap a session.
3. For each rider you see: age group, riding experience, notes, emergency contact (**tap to call**), horse, payment status and check-in.
   - Payment status shows **Paid**, **Credit**, **No charge** or **Needs review**. You never see amounts. "Needs review" means an admin must look at the payment. Let the rider ride only if an admin agrees.
4. **Check in** a rider when they arrive. Check-in opens 2 hours before the start (a setting) and closes when the session ends.
5. **No-show** is only possible once the session has started.
6. Pressing check-in twice does nothing; the page says the rider is already checked in. Mistakes can be corrected afterwards by an admin only.
7. There is a printable version of the run sheet if you prefer paper.

**QR tickets.** Each rider's ticket has a QR code. Scanning it with your phone's camera opens the check-in page for that ticket, where you can press **Check in**. Customers can't use that page; it asks for a team sign-in.

**Horses and notes on the run sheet**
- Choose a horse for each rider and press **Save horse**. Horses on a rest or vet day, retired, already riding at that time, or at their daily limit can't be chosen. A horse not listed for the rider's level gives a warning only.
- After the session, trainers can tick the skills a rider showed and write a short note **for the family** (customers see it). The **session note** is for the team only.

---

## 6. Horses (admins)

**Schedule** → **Horses**.

- **Suits riders who are**: beginner, intermediate and/or confident. Used for the warning on the run sheet.
- **Most sessions a day**: the horse can't be given more riders than this on one day.
- **Add day off**: rest or vet days. The horse can't be chosen on those dates.
- **Retire** a horse that won't work again. It is never deleted, because it stays in the history of the sessions it carried. **Restore** brings it back.

---

## 7. Leads and quick replies (staff and admins)

A lead is anyone who asked about riding: through the website forms, Instagram DMs, WhatsApp, phone or in person.

**Website enquiries** appear by themselves under **Leads** and on the **Lead board**, marked "new". Admins also get an email for each one.

**Instagram DMs**
1. **Leads** → **Add lead**. Enter the name and at least one of: Instagram handle, phone or email. If it looks like someone already in the list, you'll see a warning; check before adding.
2. On the lead (or its card on the board) open **Quick replies** and press **Copy** on the reply you need. Paste it into Instagram. Prices, times and links are always today's, so never type prices yourself.
3. Links in a copied reply are tagged to that lead. If the person books and pays from that link, the lead moves to **Booked** on its own.

**Statuses:** New → Contacted → Qualified → Booked, or Lost.
- When you move a lead to **Contacted**, a follow-up date is suggested (change it if you like).
- **Lost** needs a short reason ("too expensive", "no reply"). A lost lead can be reopened.
- **Booked** happens automatically when the person pays.

**Follow-ups:** every morning at 09:00 the team gets one email listing the leads due today or overdue (your own first). The panel's home page shows the same list. No email is sent when nothing is due.

Admins write and edit the reply wording under **Reply templates**. Bank details never go in a reply: customers see them at checkout.

---

## 8. Weather alerts

When weather alerts are switched on (an admin does this in **Settings**), the site checks the forecast for the stables twice a day for sessions in the next 48 hours.

- **Watch**: one condition is forecast (heavy rain chance, rain, strong gusts, heat or cold).
- **Warning**: a thunderstorm, or two or more conditions together.
- The team members chosen in Settings get an email when a session's level changes, and an "all clear" when it improves. Trainers can be set to get alerts for their own sessions only.
- The run sheet and the Slots list show **Weather: Clear / Watch / Warning** for the next 48 hours.

**Telling customers (admins):** open the slot → **Weather notice**.
- **Weather update** and **Session moved** email every customer with a confirmed booking, once each. Edit the message before sending. "Session moved" only sends the message: you still move each booking yourself.
- **Session cancelled** cancels the slot the normal way (credits back, paid bookings marked **Needs review** for a refund or move), and the cancellation email includes your message.

The deciding is yours: the alerts are only a forecast. The bad-weather policy (who decides, free moves or refunds) is not decided yet, so the message templates make no promises.

---

## 9. Refunds (admins only)

Open the order under **Orders**. Always give a reason; it is recorded.

**Card payments:** **Refund**.
- Turn on **Refund everything not yet refunded** for a full refund. All upcoming bookings on the order are cancelled and any unused package credits are removed.
- For a partial refund, enter the amount, then pick the bookings to cancel (**Cancel these bookings**) and the **Unused credits to remove** (0 or more). A cancelled booking's credit is not given back.
- The money goes back to the customer's card through Stripe; it can take several days to appear.
- If a gift voucher or account credit paid part of the order, the same share is given back to them automatically.

**Bank transfer payments:** you send the money back from online banking first, then record it.
1. Make the transfer from Destination Pakistan's bank account to the customer.
2. On the order, choose **Record bank refund**: the amount, the **Bank reference of your transfer**, the **Transfer date** and a reason.
3. Bookings and credits are handled exactly as for a card refund.

**Orders paid only by voucher and/or account credit:** use **Cancel and restore balances**.

**Gift voucher purchases paid by transfer:** **Voucher purchases** → the purchase → **Record bank refund**. Only the part of the voucher not yet spent can be refunded.

Refunds made directly in the Stripe dashboard are picked up by the site and the order is marked for review. Prefer refunding from the order page.

The written refund policy is not published yet. Until it is, ask the owner before promising anyone a refund.

---

## 10. Gift vouchers and promo codes (admins only)

Both are under **Sales & promotions**.

**Gift vouchers**
- Customers buy them on the website for a session or an amount, by card or bank transfer. The voucher only exists once the payment is confirmed. The recipient gets an email with the code (straight away, or at 9 am on the chosen day).
- **Issue voucher** gives one without a purchase (for example as an apology after a cancelled session). Write the reason.
- **Void** cancels what is left on a voucher. It doesn't refund anyone.
- **Extend expiry** moves the end date. An expired voucher gets its balance back.
- **Change recipient** / **Resend email** until the voucher email has gone out.

**Promo codes**
- Percentage off or a fixed PKR amount, optional products (none means all), start and end dates, total uses, uses per customer, a minimum order and "first paid booking only".
- One promo code per order. It never combines with another code, but it can combine with a gift voucher.
- Once a code has been used, its code, discount and products can't be changed. Archive it and create a new one instead. A code nobody used can be deleted.

Customers type gift voucher and promo codes in the same box on the checkout page. Capitals and dashes don't matter.

---

## 11. What do I do if…

| Situation | What to do |
|---|---|
| A rider arrives but isn't on the run sheet | Check the customer's account under **Bookings** (search their name or email). If they paid by transfer that isn't approved yet, ask an admin. |
| "Needs review" on the run sheet | An admin has to check the payment before the session. Call an admin. |
| A customer says they paid by transfer but nothing changed | An admin checks **Verify transfers**. If they haven't uploaded the screenshot yet, they can do it from their checkout page or the link in their email. |
| A customer wants to cancel or move | Customers handle **package credit** bookings themselves in their account: they can move one up to the cutoff (currently 48 hours before), and cancel any time. Before the cutoff the credit comes back; after it the credit is used. Single and private bookings paid directly: an admin changes them. |
| A customer can't sign in | They sign in with a code sent to their email, not a password. Ask them to check spam and wait a minute before asking for a new code. Too many requests are blocked for a while. |
| Wrong check-in or no-show | Only an admin can correct it, in the panel under **Bookings**. |
| A customer asks for the exact location | It is in their booking confirmation and reminder emails. Never post it publicly. |
| You think an account has been misused, or you lost your phone with the authenticator | Tell an admin straight away. |
