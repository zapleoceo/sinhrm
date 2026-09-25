<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Contracts;

use App\Modules\GoogleWorkspace\DTO\GmailMessage;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;

/** Read access to the connected mailbox (Gmail API v1, users/me). */
interface GmailClient
{
    /**
     * users.messages.list with a Gmail search query.
     *
     * @return array{ids: list<string>, next: string|null}
     *
     * @throws GoogleException
     */
    public function listIds(string $query, int $max, ?string $pageToken = null): array;

    /**
     * users.messages.get (format=full), decoded to plain text.
     *
     * @throws GoogleException
     */
    public function get(string $id): GmailMessage;
}
