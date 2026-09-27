<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Short e-mail to a user about something waiting for them (an approval) or the result of their own request.
 * Best effort and never throws: skipped (and logged) when the user turned approval e-mails off, the module is
 * closed for the user, or the company mailbox cannot send. Implementation: GoogleWorkspace (Gmail).
 */
interface UserNotifier
{
    /**
     * @param  string  $module  module key (the mail is skipped when the module is not allowed for the user)
     * @param  string  $link  SPA path, e.g. /timeoff/approvals
     */
    public function notify(int $userId, string $module, string $subject, string $body, string $link): void;
}
