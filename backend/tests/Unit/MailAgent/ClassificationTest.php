<?php

declare(strict_types=1);

namespace Tests\Unit\MailAgent;

use App\Modules\GoogleWorkspace\DTO\GmailMessage;
use App\Modules\MailAgent\Contracts\SenderRuleRepository;
use App\Modules\MailAgent\Enums\ParserKey;
use App\Modules\MailAgent\Enums\SenderKind;
use App\Modules\MailAgent\Models\SenderRule;
use App\Modules\MailAgent\Services\RulesMailClassifier;
use App\Modules\MailAgent\Support\SenderPattern;
use App\Modules\MailAgent\Support\SenderSuggester;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

final class ClassificationTest extends TestCase
{
    public function test_exact_address_beats_domain_and_longer_domain_beats_shorter(): void
    {
        $classifier = new RulesMailClassifier($this->repository([
            $this->rule(1, '@example.test', SenderKind::Newsletter),
            $this->rule(2, '@jobs.example.test', SenderKind::JobBoard, ParserKey::WorkUa),
            $this->rule(3, 'boss@jobs.example.test', SenderKind::Colleague),
        ]));

        $this->assertSame(3, $classifier->classify($this->mail('Boss@Jobs.Example.Test'))?->ruleId);
        $this->assertSame(2, $classifier->classify($this->mail('robot@jobs.example.test'))?->ruleId);
        $this->assertSame(ParserKey::WorkUa, $classifier->classify($this->mail('robot@eu.jobs.example.test'))?->parser);
        $this->assertSame(1, $classifier->classify($this->mail('x@example.test'))?->ruleId);
        $this->assertNull($classifier->classify($this->mail('x@notexample.test')));
        $this->assertNull($classifier->classify(new GmailMessage('m', Carbon::now(), null, null, 's', 't')));
    }

    public function test_pattern_validation_and_specificity(): void
    {
        $this->assertTrue(SenderPattern::isValid('@work.ua'));
        $this->assertTrue(SenderPattern::isValid(' HR@Partner.Example.Test '));
        $this->assertFalse(SenderPattern::isValid('work.ua'));
        $this->assertFalse(SenderPattern::isValid('@localhost'));
        $this->assertSame(0, SenderPattern::specificity('@ua', 'a@work.ua') - 1);
        $this->assertSame(0, SenderPattern::specificity('@ork.ua', 'a@work.ua'));
    }

    public function test_suggestions_are_rule_based(): void
    {
        $this->assertSame([SenderKind::JobBoard, ParserKey::WorkUa], SenderSuggester::suggest('robot@notify.work.ua'));
        $this->assertSame([SenderKind::JobBoard, ParserKey::Djinni], SenderSuggester::suggest('no-reply@djinni.co'));
        $this->assertSame([SenderKind::Newsletter, null], SenderSuggester::suggest('newsletter@shop.example.test'));
        $this->assertSame([null, null], SenderSuggester::suggest('olena@example.test'));
    }

    private function mail(string $from): GmailMessage
    {
        return new GmailMessage('m', Carbon::now(), mb_strtolower($from), null, 'Subject', 'Text');
    }

    private function rule(int $id, string $pattern, SenderKind $kind, ?ParserKey $parser = null): SenderRule
    {
        $rule = new SenderRule(['pattern' => $pattern, 'kind' => $kind, 'parser' => $parser]);
        $rule->id = $id;

        return $rule;
    }

    /** @param  list<SenderRule>  $rules */
    private function repository(array $rules): SenderRuleRepository
    {
        return new class($rules) implements SenderRuleRepository
        {
            /** @param  list<SenderRule>  $rules */
            public function __construct(private readonly array $rules) {}

            public function all(?string $source = null): Collection
            {
                return new Collection($this->rules);
            }

            public function find(int $id): ?SenderRule
            {
                return null;
            }

            public function findByPattern(string $pattern): ?SenderRule
            {
                return null;
            }

            public function create(array $attributes): SenderRule
            {
                return new SenderRule($attributes);
            }

            public function update(SenderRule $rule, array $attributes): SenderRule
            {
                return $rule;
            }

            public function delete(SenderRule $rule): void {}

            public function hit(int $id, Carbon $at): void {}

            public function count(): int
            {
                return count($this->rules);
            }
        };
    }
}
