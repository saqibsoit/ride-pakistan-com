<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Enums\BalanceDue;
use App\Enums\BalanceStatus;
use App\Models\Order;
use App\Models\OrderBalance;
use App\Models\RideEvent;
use App\Models\Slot;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * What an event order looks like to the customer (decided 2026-11-22):
 * the event, the date, the people, what was paid and what is left. Used by
 * the emails, checkout, the manage page and the account order page.
 */
final class EventBooking
{
    public function __construct(
        public readonly Order $order,
        public readonly RideEvent $event,
        public readonly ?Slot $occurrence,
        public readonly ?OrderBalance $balance,
    ) {}

    public static function for(Order $order): ?self
    {
        if (! $order->isEvent()) {
            return null;
        }

        $order->loadMissing(['rideEvent', 'balance', 'items.slot', 'items.rider', 'items.tier']);
        $event = $order->rideEvent;

        if ($event === null) {
            return null;
        }

        return new self($order, $event, $order->items->first()?->slot, $order->balance);
    }

    public function dateLabel(): string
    {
        return $this->occurrence?->dateRangeLabel() ?? '';
    }

    /** Charged at checkout: the deposit, or the full price. */
    public function paidNowMinor(): int
    {
        return $this->order->amount_minor;
    }

    public function balanceMinor(): int
    {
        return $this->balance->amount_minor ?? $this->order->balance_minor;
    }

    public function balanceStatus(): ?BalanceStatus
    {
        if ($this->balance !== null) {
            return $this->balance->status;
        }

        return $this->order->balance_minor > 0 ? BalanceStatus::Due : null;
    }

    public function hasBalanceDue(): bool
    {
        return $this->balanceStatus() === BalanceStatus::Due && $this->balanceMinor() > 0;
    }

    public function balanceDueAt(): ?CarbonImmutable
    {
        if ($this->balance !== null) {
            return $this->balance->due_at;
        }

        return $this->occurrence === null ? null : $this->event->balanceOnlineUntil($this->occurrence);
    }

    public function canPayBalanceOnline(): bool
    {
        return $this->balance !== null && $this->balance->canPayOnline();
    }

    /** "Due on the day (Sat 19 Dec 2026)" or "Due by Fri 18 Dec 2026, 10:00". */
    public function balanceDueText(): string
    {
        $due = $this->balanceDueAt();

        if ($due === null) {
            return '';
        }

        $local = $due->setTimezone(Slot::displayTimezone());

        return $this->event->balance_due === BalanceDue::OnTheDay
            ? 'due on the day ('.$local->format('D j M Y').'), at the venue or online before'
            : 'due by '.$local->format('D j M Y, H:i').' (Pakistan time), online';
    }

    public function balanceLine(): string
    {
        return match ($this->balanceStatus()) {
            BalanceStatus::Due => 'Balance '.Money::format($this->balanceMinor(), $this->order->currency).', '.$this->balanceDueText(),
            BalanceStatus::Paid => 'Balance '.Money::format($this->balanceMinor(), $this->order->currency).' paid',
            BalanceStatus::Refunded => 'Balance refunded',
            BalanceStatus::Void, null => '',
        };
    }

    /**
     * People booked, with their tier.
     *
     * @return list<array{name: string, tier: string}>
     */
    public function people(): array
    {
        $people = [];

        foreach ($this->order->items as $item) {
            if ($item->rider !== null) {
                $people[] = ['name' => $item->rider->name, 'tier' => $item->tier->name ?? ''];
            }
        }

        return $people;
    }

    /**
     * Dress code lines from the event's content block, plus the agreement.
     *
     * @return list<string>
     */
    public function dressCode(): array
    {
        $lines = [];

        foreach ($this->event->blocksOf('dress_code') as $block) {
            if (is_string($block['text'] ?? null) && trim($block['text']) !== '') {
                $lines[] = trim($block['text']);
            }

            foreach ((array) ($block['items'] ?? []) as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $lines[] = trim($item);
                }
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    public function whatToBring(): array
    {
        $items = [];

        foreach ($this->event->blocksOf('bring') as $block) {
            foreach ((array) ($block['items'] ?? []) as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $items[] = trim($item);
                }
            }
        }

        return $items;
    }

    public function acknowledgement(): ?string
    {
        $text = $this->order->meta['acknowledgement']['text'] ?? null;

        return is_string($text) ? $text : null;
    }

    /**
     * @return list<string>
     */
    public function terms(): array
    {
        return EventTerms::lines($this->event, $this->order);
    }
}
