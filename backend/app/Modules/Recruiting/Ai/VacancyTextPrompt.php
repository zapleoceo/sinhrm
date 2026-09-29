<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Ai;

use App\Modules\Ai\Contracts\AiPromptTemplate;
use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Exceptions\InvalidAiOutput;
use App\Modules\Ai\Support\JsonOutput;
use App\Modules\Ai\Support\PromptBuilder;

/**
 * «Створити з ШІ» in the vacancy form (versioned, full text in docs/modules/ai.md — keep identical): a Markdown draft
 * of one section from the vacancy facts. Input: section, title, category, branch name, employment type, experience —
 * no people, no personal data. System = instructions only (stable prefix); the facts are the user message.
 */
final class VacancyTextPrompt implements AiPromptTemplate
{
    public const string VERSION = 'vacancy_text.v1';

    public const int MAX_TOKENS = 900;

    /** Longest accepted draft (characters); the section field itself allows 10 000. */
    public const int MAX_TEXT = 4000;

    public const array SECTIONS = ['description', 'requirements', 'responsibilities', 'additional_info'];

    // prompt cache: prefix < 1024 tokens — instructions only, byte-stable (no dates, ids or names).
    public const string ROLE = 'HR copywriter of job ads; a recruiter edits the draft.';

    public const string TASK = 'Write a draft of one section of a vacancy from its facts (user message).';

    /** @var list<string> */
    public const array RULES = [
        'section: description = 2-4 sentences about the role; requirements, responsibilities = 4-7 list items; additional_info = 2-4 list items about conditions or the hiring process.',
        'Typical duties and skills of the role are allowed; never invent salary, address, company name or benefits.',
        'Markdown only: paragraphs or "- " list items; no headings, links or emojis.',
        'Inclusive wording: no age, gender, nationality, health or appearance requirements.',
    ];

    public const string OUTPUT = '{"text":str}';

    public function purpose(): AiPurpose
    {
        return AiPurpose::VacancyText;
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function fromFixture(array $input): AiPrompt
    {
        return self::build($input);
    }

    public function skipReason(array $input): ?string
    {
        return trim(is_string($input['title'] ?? null) ? $input['title'] : '') === '' ? 'insufficient_data' : null;
    }

    public function parse(string $text): array
    {
        return self::parseJson(JsonOutput::decode($text) ?? throw InvalidAiOutput::because('not_json'));
    }

    /** Expected: {min_length?}. */
    public function compare(array $parsed, array $expected): array
    {
        $text = (string) ($parsed['text'] ?? '');

        return [
            'has_text' => $text !== '',
            'min_length' => mb_strlen($text) >= (int) ($expected['min_length'] ?? 1),
            'no_html' => $text === strip_tags($text),
        ];
    }

    /**
     * @param  array<string, mixed>  $input  {section, title, category?, branch?, employment_type?, experience?}
     */
    public static function build(array $input): AiPrompt
    {
        $str = static fn (string $key, int $limit): string => PromptBuilder::cut(is_string($input[$key] ?? null) ? $input[$key] : null, $limit);
        $section = in_array($input['section'] ?? null, self::SECTIONS, true) ? (string) $input['section'] : 'description';

        return new AiPrompt(
            purpose: AiPurpose::VacancyText,
            version: self::VERSION,
            system: PromptBuilder::system(self::ROLE, self::TASK, self::RULES, self::OUTPUT),
            user: PromptBuilder::data([
                'section' => $section,
                'vacancy' => [
                    'title' => $str('title', 200),
                    'category' => $str('category', 100),
                    'branch' => $str('branch', 100),
                    'employment_type' => $str('employment_type', 30),
                    'experience' => $str('experience', 30),
                ],
            ]),
            maxTokens: self::MAX_TOKENS,
            temperature: 0.5,
            schema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['text'],
                'properties' => ['text' => ['type' => 'string']],
            ],
            schemaName: 'vacancy_text',
        );
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{text: string}
     *
     * @throws InvalidAiOutput
     */
    public static function parseJson(array $json): array
    {
        $text = is_string($json['text'] ?? null) ? trim($json['text']) : '';
        if ($text === '') {
            throw InvalidAiOutput::because('empty_text');
        }

        // Markdown only: any HTML the model returns is dropped (the page escapes it anyway).
        return ['text' => mb_substr(strip_tags($text), 0, self::MAX_TEXT)];
    }
}
