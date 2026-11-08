<?php

declare(strict_types=1);

namespace App\Actions\Weather;

use App\Actions\Slots\ChangeSlotStatus;
use App\Enums\BookingStatus;
use App\Enums\SlotStatus;
use App\Enums\WeatherNoticeTemplate;
use App\Exceptions\WeatherNoticeRejected;
use App\Models\Booking;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\WeatherNotice;
use App\Support\Notifier;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;

/**
 * An admin's weather email to everyone booked in a slot (milestone 10f).
 *
 * - Update or moved: one WeatherNotice per account holder with confirmed
 *   bookings, listing their riders. The same text is never sent twice to
 *   the same customer for the slot (dedupe key on the message).
 * - Cancelled: the slot is cancelled through ChangeSlotStatus with the
 *   message as the reason, so credits, refunds and flags follow the usual
 *   flow and customers get the one cancellation email that shows it.
 *
 * Logged in the audit log (template and count, not the text). At most
 * MAX_PER_HOUR notices per slot.
 */
final class SendWeatherNotice
{
    public const MAX_PER_HOUR = 5;

    public function __construct(
        private readonly Settings $settings,
        private readonly Notifier $notifier,
        private readonly ChangeSlotStatus $changeStatus,
    ) {}

    /** The template's text with ":session" filled in, to start the message from. */
    public function defaultMessage(Slot $slot, WeatherNoticeTemplate $template): string
    {
        return str_replace(':session', $slot->label(), $this->settings->string($template->setting()));
    }

    /** Customers (account holders) who would receive an update or moved notice. */
    public function recipientCount(Slot $slot): int
    {
        return $this->confirmedBookings($slot)->pluck('user_id')->unique()->count();
    }

    /**
     * @return int customers emailed (for "cancelled": customers with bookings, emailed by the cancellation)
     *
     * @throws WeatherNoticeRejected
     */
    public function handle(Slot $slot, WeatherNoticeTemplate $template, string $message, User $admin): int
    {
        $message = trim(str_replace("\r\n", "\n", $message));

        if ($message === '') {
            throw WeatherNoticeRejected::emptyMessage();
        }

        if (mb_strlen($message) > $template->maxLength()) {
            throw WeatherNoticeRejected::tooLong($template->maxLength());
        }

        $slot->refresh();

        if ($slot->status === SlotStatus::Cancelled || $slot->starts_at->lessThanOrEqualTo(now())) {
            throw WeatherNoticeRejected::slotNotUpcoming();
        }

        $bookings = $this->confirmedBookings($slot);

        if ($bookings->isEmpty()) {
            throw WeatherNoticeRejected::noRiders();
        }

        $limiter = 'weather-notice:'.$slot->id;

        if (RateLimiter::tooManyAttempts($limiter, self::MAX_PER_HOUR)) {
            throw WeatherNoticeRejected::tooMany((int) ceil(RateLimiter::availableIn($limiter) / 60));
        }

        RateLimiter::hit($limiter, 3600);

        $customers = $bookings->pluck('user_id')->unique()->count();

        if ($template === WeatherNoticeTemplate::Cancelled) {
            $this->changeStatus->handle($slot, SlotStatus::Cancelled, $admin, $message);
            $this->log($slot, $template, $customers, $admin);

            return $customers;
        }

        $hash = substr(hash('sha256', $template->value."\n".$message), 0, 16);
        $sent = 0;

        foreach ($bookings->groupBy('user_id') as $userBookings) {
            /** @var Booking $first */
            $first = $userBookings->first();
            $customer = $first->user;

            $key = sprintf('%s:Slot:%d:%d:%s', WeatherNotice::type(), $slot->id, $customer->id, $hash);

            if ($this->notifier->send($customer, new WeatherNotice($slot, $template, $message, $userBookings->values()), $key)) {
                $sent++;
            }
        }

        if ($sent === 0) {
            throw WeatherNoticeRejected::alreadySent();
        }

        $this->log($slot, $template, $sent, $admin);

        return $sent;
    }

    /**
     * @return Collection<int, Booking>
     */
    private function confirmedBookings(Slot $slot): Collection
    {
        return Booking::query()
            ->where('slot_id', $slot->id)
            ->where('status', BookingStatus::Confirmed)
            ->with(['user', 'rider'])
            ->orderBy('id')
            ->get();
    }

    private function log(Slot $slot, WeatherNoticeTemplate $template, int $customers, User $admin): void
    {
        activity('weather')
            ->performedOn($slot)
            ->causedBy($admin)
            ->event('weather_notice')
            ->withProperties(['template' => $template->value, 'customers' => $customers])
            ->log(sprintf('Weather notice "%s" for %s to %d %s', $template->label(), $slot->label(), $customers, $customers === 1 ? 'customer' : 'customers'));
    }
}
