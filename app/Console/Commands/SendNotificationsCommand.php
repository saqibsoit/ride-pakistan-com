<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Notifications\SendCreditExpiryWarnings;
use App\Actions\Notifications\SendSessionReminders;
use App\Actions\Notifications\SendThankYous;
use Illuminate\Console\Command;

/**
 * Scheduled customer emails (milestone 8):
 * notifications:send reminders | credits | thanks
 */
final class SendNotificationsCommand extends Command
{
    protected $signature = 'notifications:send {kind : reminders, credits or thanks}';

    protected $description = 'Queue session reminders, credit expiry warnings or thank-you emails (each sent once)';

    public function handle(SendSessionReminders $reminders, SendCreditExpiryWarnings $credits, SendThankYous $thanks): int
    {
        $kind = (string) $this->argument('kind');

        $count = match ($kind) {
            'reminders' => $reminders->handle(),
            'credits' => $credits->handle(),
            'thanks' => $thanks->handle(),
            default => null,
        };

        if ($count === null) {
            $this->error('Unknown kind "'.$kind.'". Use reminders, credits or thanks.');

            return self::INVALID;
        }

        $this->info('Queued '.$count.' '.$kind.'.');

        return self::SUCCESS;
    }
}
