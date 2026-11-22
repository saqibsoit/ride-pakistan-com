<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Events\SendBalanceReminders;
use App\Actions\Notifications\SendCreditExpiryWarnings;
use App\Actions\Notifications\SendSessionReminders;
use App\Actions\Notifications\SendThankYous;
use Illuminate\Console\Command;

/**
 * Scheduled customer emails (milestone 8):
 * notifications:send reminders | credits | thanks | balances (event balances, decided 2026-11-22)
 */
final class SendNotificationsCommand extends Command
{
    protected $signature = 'notifications:send {kind : reminders, credits, thanks or balances}';

    protected $description = 'Queue session reminders, credit expiry warnings, thank-you emails or event balance reminders (each sent once)';

    public function handle(SendSessionReminders $reminders, SendCreditExpiryWarnings $credits, SendThankYous $thanks, SendBalanceReminders $balances): int
    {
        $kind = (string) $this->argument('kind');

        $count = match ($kind) {
            'reminders' => $reminders->handle(),
            'credits' => $credits->handle(),
            'thanks' => $thanks->handle(),
            'balances' => $balances->handle(),
            default => null,
        };

        if ($count === null) {
            $this->error('Unknown kind "'.$kind.'". Use reminders, credits, thanks or balances.');

            return self::INVALID;
        }

        $this->info('Queued '.$count.' '.$kind.'.');

        return self::SUCCESS;
    }
}
