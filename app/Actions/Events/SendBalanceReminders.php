<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Enums\BalanceStatus;
use App\Enums\OrderStatus;
use App\Enums\SettingKey;
use App\Models\OrderBalance;
use App\Notifications\EventBalanceReminder;
use App\Support\Notifier;
use App\Support\Settings;
use Carbon\CarbonImmutable;

/**
 * Event balances due within events.balance_reminder_hours [48] get one
 * reminder each (decided 2026-11-22), run by notifications:send balances
 * every 15 minutes. Deduplicated per order; 0 turns reminders off.
 * Unpaid balances are never cancelled: the run sheet shows them.
 */
final class SendBalanceReminders
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly Settings $settings,
    ) {}

    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $hours = $this->settings->int(SettingKey::EventBalanceReminderHours) ?? 48;

        if ($hours <= 0) {
            return 0;
        }

        $sent = 0;

        $balances = OrderBalance::query()
            ->with('order.user')
            ->where('status', BalanceStatus::Due)
            ->where('due_at', '>', $now)
            ->where('due_at', '<=', $now->addHours($hours))
            ->whereHas('order', fn ($q) => $q->where('status', OrderStatus::Paid))
            ->get();

        foreach ($balances as $balance) {
            $order = $balance->order;

            if ($this->notifier->send($order->user, new EventBalanceReminder($order), Notifier::key(EventBalanceReminder::type(), $order, $order->user))) {
                $sent++;
            }
        }

        return $sent;
    }
}
