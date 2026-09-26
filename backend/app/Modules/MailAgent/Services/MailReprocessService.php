<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Services;

use App\Modules\GoogleWorkspace\Contracts\GmailClient;
use App\Modules\MailAgent\Contracts\MailLogRepository;
use App\Modules\MailAgent\Support\MailRecord;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * After a new rule (an auto-applied AI classification) the messages of that sender that went to the unknown-senders
 * queue are run through the normal pipeline again: fetched from Gmail by id → MailMessageProcessor → the mail_messages
 * row is updated. Idempotent: only rows still in outcome "unknown" are touched, and touchpoints are keyed by the Gmail
 * id. Gmail not connected → nothing happens (the messages stay "unknown"; the next letters follow the rule).
 */
final readonly class MailReprocessService
{
    /** At most this many queued messages per sender are re-processed (the rest stay in the log as "unknown"). */
    public const int LIMIT = 20;

    public function __construct(
        private GmailClient $gmail,
        private MailSyncService $sync,
        private MailLogRepository $log,
        private MailMessageProcessor $processor,
        private LoggerInterface $logger,
    ) {}

    /** @return array{reprocessed: int, errors: int} */
    public function reprocessSender(string $email): array
    {
        $counts = ['reprocessed' => 0, 'errors' => 0];
        if (! $this->sync->connected()) {
            return $counts;
        }
        $actor = $this->sync->backgroundActor();
        foreach ($this->log->unknownFrom($email, self::LIMIT) as $gmailId) {
            try {
                $message = $this->gmail->get($gmailId);
                $result = $this->processor->process($message, $actor);
                $attributes = MailRecord::attributes($message, $result);
                unset($attributes['gmail_id']);
                if ($this->log->updateUnknown($gmailId, $attributes)) {
                    $counts['reprocessed']++;
                }
            } catch (Throwable $e) {
                // Class only: messages of lower layers may contain mail content.
                $counts['errors']++;
                $this->logger->warning('mail.reprocess_failed', ['exception' => $e::class]);
            }
        }

        return $counts;
    }
}
