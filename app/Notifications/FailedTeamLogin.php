<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To a team account and every admin: repeated failed sign-ins to the
 * admin panel for that account (App\Actions\Auth\RecordFailedTeamLogin).
 */
final class FailedTeamLogin extends CustomerNotification
{
    public function __construct(
        public readonly User $account,
        public readonly int $attempts,
        public readonly int $windowMinutes,
        public readonly ?string $ip,
        public readonly ?string $userAgent,
        public readonly CarbonImmutable $at,
    ) {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'failed_team_login';
    }

    public static function label(): string
    {
        return 'Failed admin sign-ins (admins)';
    }

    public function subject(): Model
    {
        return $this->account;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $own = $notifiable instanceof User && $notifiable->is($this->account);

        return (new MailMessage)
            ->subject('Security alert: failed admin sign-ins')
            ->markdown('mail.failed-team-login', [
                'account' => $this->account,
                'own' => $own,
                'attempts' => $this->attempts,
                'windowMinutes' => $this->windowMinutes,
                'ip' => $this->ip,
                'userAgent' => $this->userAgent,
                'at' => $this->at->setTimezone('Asia/Karachi'),
            ]);
    }
}
