<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Ai;

use App\Modules\Ai\Contracts\AiResultHandler;
use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Models\AiRequest;
use App\Modules\MailAgent\Contracts\UnknownSenderRepository;
use App\Modules\MailAgent\Enums\ParserKey;
use App\Modules\MailAgent\Enums\SenderKind;
use App\Modules\MailAgent\Services\MailAgentService;
use App\Modules\MailAgent\Services\MailReprocessService;
use Psr\Log\LoggerInterface;

/**
 * Result of the AI classification of a queued sender (subject "unknown_sender"), owner decision:
 * - confidence ≥ AUTO_APPLY_CONFIDENCE and a concrete signal (MailClassificationPrompt::autoApplicable) → an exact-address sender rule with source "ai" is created automatically
 *   (MailAgentService::createAiRule) and the sender's queued messages are re-processed by the normal pipeline;
 *   the superadmin sees such rules (filter "created by AI") and can delete them — already processed messages are
 *   not re-processed back;
 * - below → the suggestion stays in the queue for the superadmin. Extracted applicant contacts are kept only for a
 *   candidate / job-board letter with confidence ≥ PREFILL_CONFIDENCE (prefill of the confirmation).
 */
final readonly class MailClassificationAiHandler implements AiResultHandler
{
    public const string SUBJECT = 'unknown_sender';

    /** From this confidence the classification becomes a sender rule without a person (owner decision). */
    public const float AUTO_APPLY_CONFIDENCE = 0.85;

    public function __construct(
        private UnknownSenderRepository $senders,
        private MailAgentService $mail,
        private MailReprocessService $reprocess,
        private LoggerInterface $log,
    ) {}

    public function purpose(): AiPurpose
    {
        return AiPurpose::MailClassification;
    }

    public function parse(array $json, AiRequest $request): array
    {
        return MailClassificationPrompt::parseJson($json);
    }

    public function apply(AiRequest $request, array $data): void
    {
        if ($request->subject_type !== self::SUBJECT || $request->subject_id === null) {
            return;
        }
        $kind = SenderKind::tryFrom((string) ($data['kind'] ?? ''));
        $confidence = (float) ($data['confidence'] ?? 0);
        $sender = $this->senders->find($request->subject_id);
        if ($kind !== null && $sender !== null && MailClassificationPrompt::autoApplicable($sender->email, $data)) {
            $rule = $this->mail->createAiRule(
                $request->subject_id,
                $kind,
                ParserKey::tryFrom((string) ($data['parser'] ?? '')),
                $confidence,
                $request->prompt_version,
                $request->id,
            );
            if ($rule !== null) {
                $counts = $this->reprocess->reprocessSender($sender->email);
                $this->log->info('mail.ai_rule_applied', ['rule_id' => $rule->id, 'ai_request_id' => $request->id] + $counts);

                return;
            }
        }
        $this->senders->saveAiSuggestion($request->subject_id, $request->id, 'done', [
            'ai_kind' => $data['kind'] ?? null,
            'ai_parser' => $data['parser'] ?? null,
            'ai_confidence' => $data['confidence'] ?? null,
            'ai_extracted' => $data['extracted'] ?? null,
        ]);
    }

    public function failed(AiRequest $request, string $error): void
    {
        if ($request->subject_type === self::SUBJECT && $request->subject_id !== null) {
            $this->senders->saveAiSuggestion($request->subject_id, $request->id, 'failed', []);
        }
    }

    public function rebuild(AiRequest $request): ?AiPrompt
    {
        // The e-mail body is not stored (only address + subject): the prompt cannot be rebuilt.
        return null;
    }
}
