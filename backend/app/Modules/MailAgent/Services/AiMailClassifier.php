<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Services;

use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Exceptions\AiException;
use App\Modules\Ai\Services\AiService;
use App\Modules\GoogleWorkspace\Contracts\GmailClient;
use App\Modules\GoogleWorkspace\DTO\GmailMessage;
use App\Modules\MailAgent\Ai\MailClassificationAiHandler;
use App\Modules\MailAgent\Ai\MailClassificationPrompt;
use App\Modules\MailAgent\Contracts\MailLogRepository;
use App\Modules\MailAgent\Contracts\UnknownSenderRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * AI classification of a sender that landed in the unknown-senders queue (prompt mail_classify.v1). Mail is still
 * processed by the rules; the AI answer either becomes a rule by itself (confidence ≥ 0.85, owner decision — see
 * MailClassificationAiHandler) or stays a suggestion in the queue. Asked once per queued sender, without waiting (a
 * sync run handles many letters): the ai.poll job applies the answer.
 */
final readonly class AiMailClassifier
{
    public function __construct(
        private AiService $ai,
        private UnknownSenderRepository $senders,
        private MailLogRepository $mailLog,
        private GmailClient $gmail,
        private LoggerInterface $logger,
    ) {}

    /**
     * Queued senders the AI was never asked about (seen while AI was off, or over the cap): their newest logged letter
     * is fetched from Gmail again and classified. Called by the mail.sync job after the sync; stops at the daily cap.
     *
     * @return int senders submitted
     */
    public function classifyQueued(int $limit): int
    {
        if (! $this->ai->available(AiPurpose::MailClassification)) {
            return 0;
        }
        $submitted = 0;
        foreach ($this->senders->withoutAiSuggestion($limit) as $sender) {
            $gmailId = $this->mailLog->latestFrom($sender->email);
            if ($gmailId === null) {
                continue;
            }
            try {
                $message = $this->gmail->get($gmailId);
            } catch (Throwable $e) {
                $this->logger->info('mail.ai_backlog_fetch_failed', ['exception' => $e::class]);

                continue;
            }
            if (! $this->suggest($message, $sender->id)) {
                break;
            }
            $submitted++;
        }

        return $submitted;
    }

    /** @return bool false when AI refused (off, over the cap): callers stop asking */
    public function suggest(GmailMessage $message, int $unknownSenderId): bool
    {
        if ($message->fromEmail === null || ! $this->ai->available(AiPurpose::MailClassification)) {
            return false;
        }
        try {
            $outcome = $this->ai->run(
                MailClassificationPrompt::build($message),
                MailClassificationAiHandler::SUBJECT,
                $unknownSenderId,
                waitSeconds: 0,
            );
        } catch (AiException $e) {
            // Over the cap or switched off: the queue works without suggestions.
            $this->logger->info('mail.ai_suggestion_skipped', ['code' => $e->errorCode]);

            return false;
        }
        if ($outcome->isDeferred()) {
            $this->senders->saveAiSuggestion($unknownSenderId, $outcome->requestId, 'pending', []);
        }

        return true;
    }
}
