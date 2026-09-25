<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Services;

use App\Modules\GoogleWorkspace\DTO\GmailMessage;
use App\Modules\MailAgent\Contracts\MailClassifier;
use App\Modules\MailAgent\Contracts\SenderRuleRepository;
use App\Modules\MailAgent\DTO\Classification;
use App\Modules\MailAgent\Models\SenderRule;
use App\Modules\MailAgent\Support\SenderPattern;

/**
 * sender_rules: an exact address rule wins over a domain rule, a longer domain over a shorter one. Rules are few,
 * so they are loaded once per instance (one sync run) and matched in PHP.
 */
final class RulesMailClassifier implements MailClassifier
{
    /** @var list<SenderRule>|null */
    private ?array $rules = null;

    public function __construct(private readonly SenderRuleRepository $repository) {}

    public function classify(GmailMessage $message): ?Classification
    {
        if ($message->fromEmail === null) {
            return null;
        }
        $best = null;
        $bestScore = [0, 0];
        foreach ($this->rules() as $rule) {
            $score = [SenderPattern::specificity($rule->pattern, $message->fromEmail), mb_strlen($rule->pattern)];
            if ($score[0] > 0 && $score > $bestScore) {
                $best = $rule;
                $bestScore = $score;
            }
        }

        return $best === null ? null : new Classification($best->kind, $best->parser, $best->id);
    }

    /** @return list<SenderRule> */
    private function rules(): array
    {
        return $this->rules ??= array_values($this->repository->all()->all());
    }
}
