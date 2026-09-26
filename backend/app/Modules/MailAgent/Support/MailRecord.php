<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Support;

use App\Modules\GoogleWorkspace\DTO\GmailMessage;
use App\Modules\MailAgent\DTO\ProcessResult;

/** The mail_messages row of a processed message (no body): shared by the sync and the re-processing after an AI rule. */
final class MailRecord
{
    /** @return array<string, mixed> */
    public static function attributes(GmailMessage $message, ProcessResult $result): array
    {
        return [
            'gmail_id' => $message->id,
            'received_at' => $message->receivedAt,
            'sender' => $message->fromEmail,
            'subject' => mb_substr($message->subject, 0, 255),
            'kind' => $result->classification?->kind->value,
            'parser' => $result->classification?->parser?->value,
            'outcome' => $result->outcome->value,
            'error' => $result->error,
            'candidate_id' => $result->candidateId,
            'touchpoint_id' => $result->touchpointId,
        ];
    }
}
