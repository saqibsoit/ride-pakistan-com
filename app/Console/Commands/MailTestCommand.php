<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Mail\ReportMailFailure;
use App\Mail\TestMail;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Message;
use Throwable;

/**
 * Sends a branded test email straight away (not queued) through the
 * configured mailer and reports what the provider said (milestone 12).
 */
final class MailTestCommand extends Command
{
    protected $signature = 'mail:test {email : Where to send the test email}';

    protected $description = 'Send a branded test email now and report success or the error';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->components->error("[{$email}] is not a valid email address.");

            return self::INVALID;
        }

        $mailer = (string) config('mail.default');
        $this->components->info("Sending a test email to {$email} with the [{$mailer}] mailer...");

        try {
            $sent = Mail::to($email)->send(new TestMail(CarbonImmutable::now()));
        } catch (Throwable $e) {
            $this->components->error('Sending failed: '.$e::class);
            $this->line('  '.ReportMailFailure::safeMessage($e));

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('From', (string) config('mail.from.address'));
        $this->components->twoColumnDetail('Reply-To', (string) config('mail.reply_to.address'));

        if ($sent instanceof SentMessage) {
            $original = $sent->getOriginalMessage();
            $resendId = $original instanceof Message
                ? $original->getHeaders()->get('X-Resend-Email-ID')?->getBodyAsString()
                : null;
            $this->components->twoColumnDetail('Message ID', $sent->getMessageId());

            if ($resendId !== null) {
                $this->components->twoColumnDetail('Resend email ID', $resendId);
            }
        }

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->components->warn("The [{$mailer}] mailer doesn't deliver anything; set MAIL_MAILER=resend to send for real.");
        }

        $this->components->info('Sent.');

        return self::SUCCESS;
    }
}
