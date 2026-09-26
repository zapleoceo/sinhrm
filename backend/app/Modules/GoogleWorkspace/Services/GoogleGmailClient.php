<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Services;

use App\Modules\GoogleWorkspace\Contracts\GmailClient;
use App\Modules\GoogleWorkspace\DTO\GmailMessage;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;
use App\Modules\GoogleWorkspace\Support\GoogleApi;
use App\Modules\GoogleWorkspace\Support\MimeText;
use Illuminate\Support\Carbon;

/** Gmail API v1 over plain REST (https://gmail.googleapis.com/gmail/v1/users/me/...). */
final readonly class GoogleGmailClient implements GmailClient
{
    public const string BASE = 'https://gmail.googleapis.com/gmail/v1/users/me';

    public function __construct(private GoogleApi $api) {}

    public function listIds(string $query, int $max, ?string $pageToken = null): array
    {
        $params = ['q' => $query, 'maxResults' => $max];
        if ($pageToken !== null) {
            $params['pageToken'] = $pageToken;
        }
        $json = $this->api->get(GoogleService::Gmail, self::BASE.'/messages', $params);
        $ids = [];
        foreach (is_array($json['messages'] ?? null) ? $json['messages'] : [] as $row) {
            if (is_array($row) && is_string($row['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $row['id']) === 1) {
                $ids[] = $row['id'];
            }
        }

        return ['ids' => $ids, 'next' => is_string($json['nextPageToken'] ?? null) ? $json['nextPageToken'] : null];
    }

    public function get(string $id): GmailMessage
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1) {
            throw GoogleException::badResponse();
        }
        $json = $this->api->get(GoogleService::Gmail, self::BASE.'/messages/'.$id, ['format' => 'full']);
        $payload = is_array($json['payload'] ?? null) ? $json['payload'] : [];
        [$email, $name] = MimeText::parseAddress(MimeText::header($payload, 'From'));
        $internal = is_numeric($json['internalDate'] ?? null) ? (int) $json['internalDate'] : null;
        $labels = is_array($json['labelIds'] ?? null) ? array_values(array_filter($json['labelIds'], 'is_string')) : [];

        return new GmailMessage(
            id: $id,
            receivedAt: $internal !== null ? Carbon::createFromTimestampMs($internal) : Carbon::now(),
            fromEmail: $email,
            fromName: $name,
            subject: mb_substr(trim((string) MimeText::header($payload, 'Subject')), 0, 500),
            text: MimeText::extract($payload),
            labelIds: $labels,
            threadId: is_string($json['threadId'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $json['threadId']) === 1 ? $json['threadId'] : null,
            messageId: self::messageId(MimeText::header($payload, 'Message-ID')),
        );
    }

    private static function messageId(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return preg_match('/^<[^\s<>]{3,500}>$/', $value) === 1 ? $value : null;
    }
}
