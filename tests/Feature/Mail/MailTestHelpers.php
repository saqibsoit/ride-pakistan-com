<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Makes the default mailer fail every send, like Resend refusing a request.
 * Recipients listed in $except still get through (listen for MessageSent
 * to see them), so a test can let the admin alert out.
 *
 * @param  list<string>  $except
 */
function failingMailer(array $except = []): void
{
    Mail::extend('failing', fn (): AbstractTransport => new class($except) extends AbstractTransport
    {
        /** @param list<string> $except */
        public function __construct(private readonly array $except)
        {
            parent::__construct();
        }

        protected function doSend(SentMessage $message): void
        {
            foreach ($message->getEnvelope()->getRecipients() as $recipient) {
                if (in_array($recipient->getAddress(), $this->except, true)) {
                    return;
                }
            }

            throw new TransportException('Request to the Resend API failed. Reason: rejected sender for customer@example.test', 422);
        }

        public function __toString(): string
        {
            return 'failing://';
        }
    });

    config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);
    app('mail.manager')->purge('failing');
}
