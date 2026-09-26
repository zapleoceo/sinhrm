<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Services;

use App\Models\User;
use App\Modules\GoogleWorkspace\DTO\GmailMessage;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\MailAgent\Contracts\MailClassifier;
use App\Modules\MailAgent\Contracts\SenderRuleRepository;
use App\Modules\MailAgent\Contracts\UnknownSenderRepository;
use App\Modules\MailAgent\DTO\Classification;
use App\Modules\MailAgent\DTO\IncomingApplication;
use App\Modules\MailAgent\DTO\ProcessResult;
use App\Modules\MailAgent\Enums\MailOutcome;
use App\Modules\MailAgent\Enums\ParserKey;
use App\Modules\MailAgent\Enums\SenderKind;
use App\Modules\MailAgent\Support\ParserRegistry;
use App\Modules\MailAgent\Support\SenderSuggester;
use App\Modules\Recruiting\Contracts\CandidateRepository;
use App\Modules\Recruiting\Contracts\TouchpointIngestor;
use App\Modules\Recruiting\Contracts\VacancyRepository;
use App\Modules\Recruiting\DTO\CandidateData;
use App\Modules\Recruiting\DTO\IncomingMessage;
use App\Modules\Recruiting\Enums\AddedVia;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\Direction;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Recruiting\Services\CandidateService;
use App\Modules\Recruiting\Support\ContactNormalizer;
use App\Modules\Scripts\Services\TaskService;

/**
 * One inbound message → one outcome (decided by rules only; AI never decides):
 * - sent by us / no sender → skipped;
 * - sender rule: ignore / newsletter / colleague → skipped; candidate → e-mail touchpoint; job_board → parser;
 * - no rule, sender is a known candidate's e-mail → touchpoint;
 * - otherwise → unknown_senders queue (address + subject only) + an AI suggestion when AI is available
 *   (AiMailClassifier: sender, subject, first 1500 cleaned characters of the body; shown in the queue, never applied).
 * Job board: vacancy found by exact title → candidate create-or-match + application + touchpoint + "call within
 * 1 hour" task for the vacancy recruiter; vacancy not found → the touchpoint goes to the Inbox with parsed fields.
 */
final readonly class MailMessageProcessor
{
    public const int BODY_LIMIT = 5000;

    public function __construct(
        private MailClassifier $rules,
        private AiMailClassifier $ai,
        private SenderRuleRepository $ruleRepository,
        private UnknownSenderRepository $unknown,
        private ParserRegistry $parsers,
        private CandidateRepository $candidateRepository,
        private CandidateService $candidates,
        private VacancyRepository $vacancies,
        private TouchpointIngestor $ingestor,
        private ContactNormalizer $normalizer,
        private TaskService $tasks,
    ) {}

    public function process(GmailMessage $message, ?User $actor): ProcessResult
    {
        if ($message->isSent() || $message->fromEmail === null) {
            return new ProcessResult(MailOutcome::Skipped);
        }
        $classification = $this->classify($message);
        if ($classification === null) {
            [$kind, $parser] = SenderSuggester::suggest($message->fromEmail);
            $sender = $this->unknown->touch($message->fromEmail, $message->subject, $message->receivedAt, $kind, $parser);
            if ($sender !== null && $sender->ai_status === null) {
                // A suggestion for the queue only (asked once per sender); the superadmin still confirms a rule.
                $this->ai->suggest($message, $sender->id);
            }

            return new ProcessResult(MailOutcome::Unknown);
        }
        if ($classification->ruleId !== null) {
            $this->ruleRepository->hit($classification->ruleId, $message->receivedAt);
        }

        return match (true) {
            $classification->kind->isSkipped() => new ProcessResult(MailOutcome::Skipped, $classification),
            $classification->kind === SenderKind::Candidate => $this->touch($message, $classification, $message->fromEmail, []),
            default => $this->application($message, $classification, $actor),
        };
    }

    private function classify(GmailMessage $message): ?Classification
    {
        $classification = $this->rules->classify($message);
        if ($classification !== null) {
            return $classification;
        }
        $keys = $this->normalizer->keys(null, $message->fromEmail, null);
        if (! $keys->isEmpty() && $this->candidateRepository->findByContacts($keys) !== null) {
            return new Classification(SenderKind::Candidate);
        }

        return null;
    }

    private function application(GmailMessage $message, Classification $classification, ?User $actor): ProcessResult
    {
        $parser = $this->parsers->get($classification->parser);
        $parsed = $parser->parse($message);
        if ($parsed === null) {
            return new ProcessResult(MailOutcome::ParseFailed, $classification, error: 'no_contacts');
        }
        $meta = array_filter([
            'full_name' => $parsed->fullName,
            'vacancy_title' => $parsed->vacancyTitle,
            'cv_url' => $parsed->cvUrl,
            'vacancy_ref' => $parsed->vacancyRef,
            'parser' => $parser->key()->value,
        ], static fn (?string $v): bool => $v !== null);

        $vacancy = $parsed->vacancyTitle === null ? null : $this->vacancies->findOpenByTitle($parsed->vacancyTitle);
        if ($vacancy === null) {
            // Unknown vacancy: no candidate is created automatically; the message waits in the Inbox (or lands on the
            // card of an existing candidate with the same contact).
            return $this->touch($message, $classification, $parsed->contact(), $meta);
        }

        try {
            $match = $this->candidates->createOrMatch(
                $actor,
                $this->candidateData($parsed, $parser->key()),
                $vacancy,
                $message->receivedAt,
            );
        } catch (RecruitingException $e) {
            return new ProcessResult(MailOutcome::ParseFailed, $classification, error: $e->errorCode);
        }
        $candidate = $match->candidate;
        $touchpoint = $this->ingest($message, (string) ($candidate->email ?? $candidate->phone ?? $candidate->telegram_username), $meta);
        $taskCreated = false;
        if ($match->applicationCreated && $match->application !== null) {
            $taskCreated = $this->tasks->scheduleNewApplicantCall(
                $vacancy->recruiter_id,
                $candidate->id,
                $match->application->id,
                $message->receivedAt,
            );
        }

        return new ProcessResult(MailOutcome::Application, $classification, $candidate->id, $touchpoint->id, taskCreated: $taskCreated);
    }

    /** @param  array<string, string>  $meta */
    private function touch(GmailMessage $message, Classification $classification, string $contact, array $meta): ProcessResult
    {
        $touchpoint = $this->ingest($message, $contact, $meta);

        return new ProcessResult(
            $touchpoint->candidate_id === null ? MailOutcome::Inbox : MailOutcome::Touchpoint,
            $classification,
            $touchpoint->candidate_id,
            $touchpoint->id,
        );
    }

    /** @param  array<string, string>  $meta */
    private function ingest(GmailMessage $message, string $contact, array $meta): Touchpoint
    {
        $body = trim($message->subject."\n\n".$message->text);
        // For replies from the card (Gmail threadId + Message-ID). Not IncomingMessage::thread on purpose: job boards
        // put many candidates into one Gmail thread, so the thread must never decide which candidate a mail belongs to.
        $reply = array_filter(['gmail_thread' => $message->threadId, 'message_id' => $message->messageId], 'is_string');

        return $this->ingestor->ingest(new IncomingMessage(
            channel: Channel::Email,
            direction: Direction::In,
            occurredAt: $message->receivedAt,
            contact: $contact,
            body: mb_substr($body, 0, self::BODY_LIMIT),
            externalId: $message->id,
            integrationKey: GoogleService::Gmail->integrationKey(),
            viaProduct: false,
            meta: ['subject' => $message->subject, 'from' => (string) $message->fromEmail] + $reply + $meta,
        ));
    }

    private function candidateData(IncomingApplication $parsed, ParserKey $parser): CandidateData
    {
        return new CandidateData(
            fullName: $parsed->fullName,
            phone: $parsed->phone,
            email: $parsed->email,
            source: $parser->source(),
            addedVia: AddedVia::Mail,
        );
    }
}
