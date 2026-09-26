<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Contracts;

use App\Modules\GoogleWorkspace\DTO\OutgoingMail;
use App\Modules\GoogleWorkspace\DTO\SentMail;
use App\Modules\GoogleWorkspace\Enums\MailerState;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;

/** Sends one plain-text mail from the company mailbox (implementation: the connected Gmail). */
interface Mailer
{
    /** Whether send() can work now (the UI disables "Надіслати" otherwise). No network calls. */
    public function state(): MailerState;

    /**
     * @throws GoogleException google_gmail_not_connected | gmail_send_scope_missing | gmail_send_rate_limited |
     *                         invalid_mail | reconnect_required | google_* (provider errors, codes only)
     */
    public function send(OutgoingMail $mail): SentMail;
}
