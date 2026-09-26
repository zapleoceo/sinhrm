<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Ai;

use App\Modules\Ai\Contracts\AiPromptTemplate;
use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Exceptions\InvalidAiOutput;
use App\Modules\Ai\Support\JsonOutput;
use App\Modules\Ai\Support\PiiRedactor;
use App\Modules\Ai\Support\PromptBuilder;

/**
 * Prompt of the AI screening (tz6; versioned, full text in docs/modules/ai.md — keep identical).
 *
 * Data minimisation: the candidate's name, phone, e-mail, Telegram, links and the authors of notes are NOT sent; the
 * materials (CV text from the clipper note, other notes, the candidate's own messages/transcripts) go through
 * PiiRedactor. No employee data at all. System = shared-format instructions only (stable prefix); the vacancy and the
 * candidate materials are the user message as compact JSON. Pure (no DB/HTTP): the ai:experiment harness uses it.
 */
final class ScreeningPrompt implements AiPromptTemplate
{
    public const string VERSION = 'screening.v2';

    public const int MAX_TOKENS = 2500;

    public const int VACANCY_LIMIT = 4000;

    public const int MATERIALS_LIMIT = 8000;

    public const int MATERIAL_ITEM_LIMIT = 3000;

    /** Verdict thresholds (the verdict is derived from the score on the server, not taken from the model). */
    public const int VERDICT_FIT = 70;

    public const int VERDICT_MAYBE = 40;

    // prompt cache: prefix < 1024 tokens — shared-format instructions only; byte-stable (no dates, ids or names) so any
    // provider-side cache applies when its minimum allows.
    public const string ROLE = 'Recruiter assistant; the decision is made by a person.';

    public const string TASK = 'Score how well the candidate matches the vacancy requirements (user message).';

    /** @var list<string> */
    public const array RULES = [
        'score 0..100: 90+ all key requirements, 70-89 most, 40-69 partly, <40 no match or almost no data.',
        'A requirement not mentioned = a con "not confirmed", not proof of absence.',
        'Ignore age, gender, nationality, family, health, religion, appearance, names: they never affect the score.',
        'summary ≤200 chars; pros, cons ≤5 each, tied to requirements; ask ≤5 interview questions closing the cons.',
    ];

    public const string OUTPUT = '{"score":int,"summary":str,"pros":[str],"cons":[str],"ask":[str]}';

    public function purpose(): AiPurpose
    {
        return AiPurpose::CandidateScreening;
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function fromFixture(array $input): AiPrompt
    {
        return self::build(ScreeningInput::fromArray($input));
    }

    public function parse(string $text): array
    {
        return self::parseJson(JsonOutput::decode($text) ?? throw InvalidAiOutput::because('not_json'));
    }

    /** Expected: {verdict?, score_min?, score_max?}. */
    public function compare(array $parsed, array $expected): array
    {
        $score = (int) ($parsed['score'] ?? -1);
        $checks = [];
        if (array_key_exists('verdict', $expected)) {
            $checks['verdict'] = ($parsed['verdict'] ?? null) === $expected['verdict'];
        }
        if (array_key_exists('score_min', $expected)) {
            $checks['score_min'] = $score >= (int) $expected['score_min'];
        }
        if (array_key_exists('score_max', $expected)) {
            $checks['score_max'] = $score <= (int) $expected['score_max'];
        }
        $checks['has_summary'] = is_string($parsed['summary'] ?? null) && $parsed['summary'] !== '';

        return $checks;
    }

    public static function build(ScreeningInput $input): AiPrompt
    {
        $materials = [];
        $budget = self::MATERIALS_LIMIT;
        foreach ($input->materials as $material) {
            if ($budget <= 0) {
                break;
            }
            $text = PromptBuilder::cut(PiiRedactor::redact($material['body'], $input->names), min(self::MATERIAL_ITEM_LIMIT, $budget));
            if ($text !== '') {
                $budget -= mb_strlen($text);
                $materials[] = ['ch' => $material['channel'], 'text' => $text];
            }
        }

        return new AiPrompt(
            purpose: AiPurpose::CandidateScreening,
            version: self::VERSION,
            system: PromptBuilder::system(self::ROLE, self::TASK, self::RULES, self::OUTPUT),
            user: PromptBuilder::data([
                'vacancy' => [
                    'title' => PromptBuilder::cut($input->vacancyTitle, 200),
                    'position' => PromptBuilder::cut($input->position, 200),
                    'department' => PromptBuilder::cut($input->department, 200),
                    'requirements' => PromptBuilder::cut($input->requirements, self::VACANCY_LIMIT),
                ],
                'candidate' => ['city' => PromptBuilder::cut($input->city, 100), 'tags' => $input->tags],
                'materials' => $materials,
            ]),
            maxTokens: self::MAX_TOKENS,
            temperature: 0.2,
            schema: self::schema(),
            schemaName: 'candidate_screening',
        );
    }

    /**
     * Validated answer in internal names; the verdict comes from the score.
     *
     * @param  array<string, mixed>  $json
     * @return array{score: int, verdict: string, summary: string|null, strengths: list<string>, gaps: list<string>, questions: list<string>}
     *
     * @throws InvalidAiOutput
     */
    public static function parseJson(array $json): array
    {
        $score = JsonOutput::int($json, 'score', 0, 100);

        return [
            'score' => $score,
            'verdict' => self::verdict($score),
            'summary' => JsonOutput::text($json, 'summary', 400),
            'strengths' => JsonOutput::strings($json, 'pros', 5, 300),
            'gaps' => JsonOutput::strings($json, 'cons', 5, 300),
            'questions' => JsonOutput::strings($json, 'ask', 5, 300),
        ];
    }

    public static function verdict(int $score): string
    {
        return match (true) {
            $score >= self::VERDICT_FIT => 'fit',
            $score >= self::VERDICT_MAYBE => 'maybe',
            default => 'no',
        };
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $list = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['score', 'summary', 'pros', 'cons', 'ask'],
            'properties' => [
                'score' => ['type' => 'integer'],
                'summary' => ['type' => 'string'],
                'pros' => $list,
                'cons' => $list,
                'ask' => $list,
            ],
        ];
    }
}
