<?php

declare(strict_types=1);

namespace App\Modules\Scripts\DTO;

use App\Modules\Scripts\Enums\FollowupCondition;

/**
 * Everything a script version contains. Built from validated input (FormRequest) or a stored version row;
 * the same shape is stored in script_versions (one jsonb column per part).
 *
 * @phpstan-type Objection array{id: string, trigger: string, answer: string}
 * @phpstan-type Template array{id: string, key: string, title: string, text: string}
 * @phpstan-type Followup array{id: string, condition: string, delay_days: int, template_key: string|null}
 * @phpstan-type Patterns array{positive: list<string>, negative: list<string>}
 */
final readonly class ScriptContent
{
    /** Default "next step fixed" patterns (Ukrainian call/chat wording); configurable per version. */
    public const array DEFAULT_POSITIVE = ['сьогодні', 'завтра', 'записал', 'домовил'];

    public const array DEFAULT_NEGATIVE = ['подумайте'];

    /**
     * @param  list<ScriptStep>  $steps
     * @param  list<Objection>  $objections
     * @param  list<Template>  $templates
     * @param  list<Followup>  $followups
     * @param  Patterns  $nextStepPatterns
     */
    public function __construct(
        public array $steps,
        public array $objections,
        public array $templates,
        public array $followups,
        public array $nextStepPatterns,
    ) {}

    public static function empty(): self
    {
        return self::fromArray([]);
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $list = static fn (string $key): array => array_values(array_filter((array) ($data[$key] ?? []), 'is_array'));
        $str = static fn (array $row, string $key): string => trim((string) ($row[$key] ?? ''));

        $patterns = (array) ($data['next_step_patterns'] ?? []);

        return new self(
            steps: array_map(static fn (array $s): ScriptStep => ScriptStep::fromArray($s), $list('steps')),
            objections: array_map(static fn (array $o): array => [
                'id' => $str($o, 'id'), 'trigger' => $str($o, 'trigger'), 'answer' => $str($o, 'answer'),
            ], $list('objections')),
            templates: array_map(static fn (array $t): array => [
                'id' => $str($t, 'id'), 'key' => $str($t, 'key'), 'title' => $str($t, 'title'), 'text' => (string) ($t['text'] ?? ''),
            ], $list('templates')),
            followups: array_map(static fn (array $f): array => [
                'id' => $str($f, 'id'),
                'condition' => FollowupCondition::from($str($f, 'condition'))->value,
                'delay_days' => max(0, (int) ($f['delay_days'] ?? 0)),
                'template_key' => ($f['template_key'] ?? null) === null || $str($f, 'template_key') === '' ? null : $str($f, 'template_key'),
            ], $list('followups')),
            nextStepPatterns: [
                'positive' => self::patterns($patterns['positive'] ?? null, self::DEFAULT_POSITIVE),
                'negative' => self::patterns($patterns['negative'] ?? null, self::DEFAULT_NEGATIVE),
            ],
        );
    }

    /** @return array{title: string, text: string}|null */
    public function template(string $key): ?array
    {
        foreach ($this->templates as $t) {
            if ($t['key'] === $key) {
                return ['title' => $t['title'], 'text' => $t['text']];
            }
        }

        return null;
    }

    /**
     * Column values for script_versions.
     *
     * @return array{steps: list<array<string, mixed>>, objections: list<Objection>, templates: list<Template>, followups: list<Followup>, next_step_patterns: Patterns}
     */
    public function toArray(): array
    {
        return [
            'steps' => array_map(static fn (ScriptStep $s): array => $s->toArray(), $this->steps),
            'objections' => $this->objections,
            'templates' => $this->templates,
            'followups' => $this->followups,
            'next_step_patterns' => $this->nextStepPatterns,
        ];
    }

    /**
     * null (not given) → defaults; a given list (even empty) is kept as is.
     *
     * @param  list<string>  $defaults
     * @return list<string>
     */
    private static function patterns(mixed $given, array $defaults): array
    {
        if (! is_array($given)) {
            return $defaults;
        }

        return array_values(array_unique(array_filter(array_map(static fn (mixed $p): string => trim((string) $p), $given), static fn (string $p): bool => $p !== '')));
    }
}
