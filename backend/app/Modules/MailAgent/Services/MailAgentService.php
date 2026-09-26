<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Services;

use App\Models\User;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Services\GoogleConnectionStore;
use App\Modules\MailAgent\Contracts\MailLogRepository;
use App\Modules\MailAgent\Contracts\SenderRuleRepository;
use App\Modules\MailAgent\Contracts\UnknownSenderRepository;
use App\Modules\MailAgent\Enums\ParserKey;
use App\Modules\MailAgent\Enums\SenderKind;
use App\Modules\MailAgent\Exceptions\MailAgentException;
use App\Modules\MailAgent\Models\SenderRule;
use App\Modules\MailAgent\Models\UnknownSender;
use App\Modules\MailAgent\Support\SenderPattern;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/** Admin → Mail: status, sender rules, the unknown-senders queue. */
final readonly class MailAgentService
{
    public function __construct(
        private GoogleConnectionStore $connections,
        private MailLogRepository $log,
        private SenderRuleRepository $rules,
        private UnknownSenderRepository $unknown,
        private LoggerInterface $logger,
    ) {}

    /** @return array<string, mixed> */
    public function status(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $run = $this->log->lastRun();

        return [
            'connection' => $this->connections->state(GoogleService::Gmail)->toArray(),
            'last_sync' => $run === null ? null : [
                'trigger' => $run->trigger,
                'started_at' => $run->started_at->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'counts' => (object) ($run->counts ?? []),
                'error' => $run->error,
            ],
            'counts' => [
                'rules' => $this->rules->count(),
                'unknown' => $this->unknown->count(),
                'processed_24h' => $this->log->processedSince($now->copy()->subDay()),
            ],
        ];
    }

    /** @throws MailAgentException duplicate pattern */
    public function createRule(User $actor, string $pattern, SenderKind $kind, ?ParserKey $parser): SenderRule
    {
        $pattern = SenderPattern::normalize($pattern);
        if ($this->rules->findByPattern($pattern) !== null) {
            throw MailAgentException::duplicateRule();
        }
        $rule = $this->rules->create([
            'pattern' => $pattern,
            'kind' => $kind->value,
            'parser' => self::parserFor($kind, $parser)?->value,
            'created_by' => $actor->id,
        ]);
        $this->dropCovered($pattern);
        $this->logger->info('mail.rule_created', ['id' => $rule->id, 'by' => $actor->id]);

        return $rule;
    }

    /** @throws MailAgentException duplicate pattern */
    public function updateRule(User $actor, SenderRule $rule, ?string $pattern, ?SenderKind $kind, ?ParserKey $parser, bool $parserGiven): SenderRule
    {
        $changes = [];
        if ($pattern !== null) {
            $pattern = SenderPattern::normalize($pattern);
            $other = $this->rules->findByPattern($pattern);
            if ($other !== null && $other->id !== $rule->id) {
                throw MailAgentException::duplicateRule();
            }
            $changes['pattern'] = $pattern;
        }
        $kind ??= $rule->kind;
        $changes['kind'] = $kind->value;
        $changes['parser'] = self::parserFor($kind, $parserGiven ? $parser : $rule->parser)?->value;
        $this->rules->update($rule, $changes);
        $this->logger->info('mail.rule_updated', ['id' => $rule->id, 'by' => $actor->id]);

        return $rule;
    }

    public function deleteRule(User $actor, SenderRule $rule): void
    {
        $this->rules->delete($rule);
        $this->logger->info('mail.rule_deleted', ['id' => $rule->id, 'by' => $actor->id]);
    }

    /**
     * One click in the queue: a rule for the address (scope "email") or its whole domain (scope "domain");
     * the queued senders it covers leave the queue. An existing rule with the same pattern is updated.
     */
    public function assign(User $actor, UnknownSender $sender, SenderKind $kind, ?ParserKey $parser, bool $wholeDomain): SenderRule
    {
        $pattern = $wholeDomain ? '@'.SenderPattern::domainOf($sender->email) : $sender->email;
        $existing = $this->rules->findByPattern($pattern);
        $rule = $existing === null
            ? $this->createRule($actor, $pattern, $kind, $parser)
            : $this->updateRule($actor, $existing, null, $kind, $parser, true);
        $this->dropCovered($pattern);
        $this->unknown->delete($sender);

        return $rule;
    }

    /**
     * Auto-applied AI classification (confidence ≥ threshold, owner decision): an exact-address rule with source "ai",
     * no author, the confidence and the prompt version; the sender leaves the queue. null = not applied because a rule
     * for the address already exists (a person decided first) or the sender is gone from the queue.
     */
    public function createAiRule(int $unknownSenderId, SenderKind $kind, ?ParserKey $parser, float $confidence, string $promptVersion, int $requestId): ?SenderRule
    {
        $sender = $this->unknown->find($unknownSenderId);
        $pattern = $sender === null ? null : SenderPattern::normalize($sender->email);
        if ($sender === null || $pattern === null || $this->rules->findByPattern($pattern) !== null) {
            return null;
        }
        try {
            $rule = $this->rules->create([
                'pattern' => $pattern,
                'kind' => $kind->value,
                'parser' => self::parserFor($kind, $parser)?->value,
                'created_by' => null,
                'source' => SenderRule::SOURCE_AI,
                'ai_confidence' => round($confidence, 3),
                'prompt_version' => $promptVersion,
                'ai_request_id' => $requestId,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
        $this->unknown->delete($sender);
        $this->logger->info('mail.rule_created', ['id' => $rule->id, 'by' => 'ai', 'ai_request_id' => $requestId]);

        return $rule;
    }

    public function dismiss(UnknownSender $sender): void
    {
        $this->unknown->delete($sender);
    }

    private function dropCovered(string $pattern): void
    {
        if (str_starts_with($pattern, '@')) {
            $this->unknown->deleteDomain(substr($pattern, 1));
        }
    }

    /** Only job-board rules carry a parser (default: generic). */
    private static function parserFor(SenderKind $kind, ?ParserKey $parser): ?ParserKey
    {
        return $kind === SenderKind::JobBoard ? ($parser ?? ParserKey::Generic) : null;
    }
}
