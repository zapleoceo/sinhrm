<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Services;

use App\Models\User;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Core\Contracts\UserNotifier;
use App\Modules\Core\Services\ModuleAccess;
use App\Modules\GoogleWorkspace\Contracts\Mailer;
use App\Modules\GoogleWorkspace\DTO\OutgoingMail;
use App\Modules\GoogleWorkspace\Enums\MailerState;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;
use Psr\Log\LoggerInterface;

/** UserNotifier through the company mailbox (Mailer → Gmail): subject, 2-line body, link to the page. */
final readonly class MailUserNotifier implements UserNotifier
{
    public function __construct(
        private Mailer $mailer,
        private ModuleAccess $access,
        private LoggerInterface $log,
        private string $frontendUrl,
    ) {}

    public function notify(int $userId, string $module, string $subject, string $body, string $link): void
    {
        $user = User::query()->find($userId);
        if ($user === null || $user->status !== UserStatus::Active || ! $user->approval_emails || ! $this->access->allows($user, $module)) {
            return;
        }
        $state = $this->mailer->state();
        if ($state !== MailerState::Ready) {
            $this->log->info('notify.mail_skipped', ['user' => $userId, 'reason' => $state->value]);

            return;
        }
        try {
            $this->mailer->send(new OutgoingMail(
                to: $user->email,
                subject: $subject,
                text: $body."\n".rtrim($this->frontendUrl, '/').$link,
                toName: $user->name,
            ));
        } catch (GoogleException $e) {
            $this->log->warning('notify.mail_failed', ['user' => $userId, 'code' => $e->errorCode]);
        }
    }
}
