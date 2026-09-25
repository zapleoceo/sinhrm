<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Contracts;

use App\Modules\GoogleWorkspace\DTO\GmailMessage;
use App\Modules\MailAgent\DTO\IncomingApplication;
use App\Modules\MailAgent\Enums\ParserKey;

/**
 * Turns a job-board "new application" e-mail into an IncomingApplication. Must be tolerant: formats of the boards are
 * not known in advance and change without notice. Returns null when no contact (phone / e-mail) can be found.
 * Registered with the MailAgentServiceProvider::PARSERS_TAG tag.
 */
interface MailParser
{
    public function key(): ParserKey;

    public function parse(GmailMessage $message): ?IncomingApplication;
}
