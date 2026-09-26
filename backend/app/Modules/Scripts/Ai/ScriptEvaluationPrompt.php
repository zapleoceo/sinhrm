<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Ai;

use App\Modules\Ai\Contracts\AiPromptTemplate;
use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Exceptions\InvalidAiOutput;
use App\Modules\Ai\Support\JsonOutput;
use App\Modules\Ai\Support\PiiRedactor;
use App\Modules\Ai\Support\PromptBuilder;
use App\Modules\Scripts\DTO\ScriptContent;

/**
 * Prompt of the AI script evaluation (versioned; full text in docs/modules/ai.md — keep identical).
 *
 * Prompt caching: system = the shared-format instructions (identical for every call) + SCRIPT as compact JSON
 * (identical for every call of that script version) → byte-stable prefix; the broker marks the first system message
 * with cache_control, OpenAI-family models cache ≥ 1024-token prefixes automatically. No dates, touch/candidate ids or
 * names in the system part. The transcript — the only variable part — is the user message.
 */
final class ScriptEvaluationPrompt implements AiPromptTemplate
{
    public const string VERSION = 'script_eval.v4';

    /** Room for reasoning tokens + a quote and a note per step. */
    public const int MAX_TOKENS = 3000;

    /** Longest transcript sent (characters). */
    public const int TEXT_LIMIT = 12000;

    /** Per-field limits of the script rendering (characters). */
    public const int FIELD_LIMIT = 300;

    public const string ROLE = 'Reviewer of recruiter calls and chat messages against a script.';

    public const string TASK = 'Mark which SCRIPT steps the recruiter performed in the transcript (user message).';

    /** @var list<string> */
    public const array RULES = [
        'done = the step goal is clearly achieved (meaning, not keywords). A URL or [link] in the text satisfies a link step.',
        'quote = ≤200 chars copied character-for-character from the transcript (no paraphrase, no typo fixes); null if not done.',
        'note = one sentence: why done or what is missing.',
        'handled = ids of objections the candidate raised and the recruiter answered in the spirit of the script answer.',
        'next = true if the talk ends with a concrete agreed next step or, in a message, an explicit call to action with a time or channel (date/time, booked interview, link to complete by a deadline); "think about it"/"we will call" → false. Hints: SCRIPT.next_ok, SCRIPT.next_bad.',
        'tips = 0-2 concrete tips only for real problems; [] if nothing is wrong; no praise.',
        'Every SCRIPT step id exactly once, in order; only ids from SCRIPT; no score.',
    ];

    public const string OUTPUT = '{"steps":[{"id":str,"done":bool,"quote":str|null,"note":str}],"handled":[str],"next":bool,"next_quote":str|null,"tips":[str]}';

    public function purpose(): AiPurpose
    {
        return AiPurpose::ScriptEvaluation;
    }

    public function version(): string
    {
        return self::VERSION;
    }

    /** Fixture input: {script: {steps, objections, next_step_patterns}, transcript}. */
    public function fromFixture(array $input): AiPrompt
    {
        return self::build(ScriptContent::fromArray((array) ($input['script'] ?? [])), (string) ($input['transcript'] ?? ''));
    }

    public function skipReason(array $input): ?string
    {
        return trim((string) ($input['transcript'] ?? '')) === '' ? 'no_content' : null;
    }

    public function parse(string $text): array
    {
        return AiEvaluationMapper::parse(JsonOutput::decode($text) ?? throw InvalidAiOutput::because('not_json'));
    }

    /** The transcript exactly as the model saw it (redacted, cut): quotes are checked against it. */
    public static function sentText(string $text): string
    {
        return mb_substr(PiiRedactor::redact(trim($text)), 0, self::TEXT_LIMIT);
    }

    /** Expected: {done_steps: [ids], next_step_fixed: bool, objections_handled?: [ids]}. */
    public function compare(array $parsed, array $expected): array
    {
        $steps = is_array($parsed['steps'] ?? null) ? $parsed['steps'] : [];
        $done = array_map('strval', array_keys(array_filter($steps, static fn (mixed $s): bool => is_array($s) && ($s['done'] ?? false) === true)));
        $want = array_map('strval', (array) ($expected['done_steps'] ?? []));
        sort($done);
        sort($want);
        $checks = [
            'done_steps' => $done === $want,
            'next_step_fixed' => ($parsed['next_step_fixed'] ?? null) === ($expected['next_step_fixed'] ?? null),
        ];
        if (array_key_exists('objections_handled', $expected)) {
            $got = array_map('strval', (array) ($parsed['objections_handled'] ?? []));
            $exp = array_map('strval', (array) $expected['objections_handled']);
            sort($got);
            sort($exp);
            $checks['objections_handled'] = $got === $exp;
        }

        return $checks;
    }

    public static function build(ScriptContent $script, string $text): AiPrompt
    {
        return new AiPrompt(
            purpose: AiPurpose::ScriptEvaluation,
            version: self::VERSION,
            system: self::system($script),
            user: PromptBuilder::data(['transcript' => self::sentText($text)]),
            maxTokens: self::MAX_TOKENS,
            temperature: 0.1,
            schema: self::schema(),
            schemaName: 'script_evaluation',
        );
    }

    /** Shared-format instructions + SCRIPT (compact JSON, deterministic: same version → same bytes). */
    public static function system(ScriptContent $script): string
    {
        $cut = static fn (string $s): string => PromptBuilder::cut($s, self::FIELD_LIMIT);

        return PromptBuilder::system(self::ROLE, self::TASK, self::RULES, self::OUTPUT, ['SCRIPT' => PromptBuilder::data([
            'steps' => array_map(static fn ($s): array => [
                'id' => $s->id, 'title' => $cut($s->title), 'req' => $s->required, 'w' => $s->weight,
                'goal' => $cut($s->goal), 'sample' => $cut($s->sample),
            ], $script->steps),
            'objections' => array_map(static fn (array $o): array => [
                'id' => $o['id'], 'says' => $cut($o['trigger']), 'answer' => $cut($o['answer']),
            ], $script->objections),
            'next_ok' => $script->nextStepPatterns['positive'],
            'next_bad' => $script->nextStepPatterns['negative'],
        ])]);
    }

    /** @return array<string, mixed> strict JSON schema (all properties required, no extras) */
    public static function schema(): array
    {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['steps', 'handled', 'next', 'next_quote', 'tips'],
            'properties' => [
                'steps' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['id', 'done', 'quote', 'note'],
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'done' => ['type' => 'boolean'],
                            'quote' => $nullableString,
                            'note' => ['type' => 'string'],
                        ],
                    ],
                ],
                'handled' => ['type' => 'array', 'items' => ['type' => 'string']],
                'next' => ['type' => 'boolean'],
                'next_quote' => $nullableString,
                'tips' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }
}
