<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Ai;

use App\Modules\Ai\Contracts\AiPromptTemplate;
use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Exceptions\InvalidAiOutput;
use App\Modules\Ai\Support\JsonOutput;
use App\Modules\Ai\Support\PromptBuilder;
use App\Modules\GoogleWorkspace\DTO\GmailMessage;
use App\Modules\MailAgent\Enums\ParserKey;
use App\Modules\MailAgent\Enums\SenderKind;
use App\Modules\MailAgent\Support\MailBodyCleaner;
use App\Modules\MailAgent\Support\SenderSuggester;
use Illuminate\Support\Carbon;

/**
 * Prompt of the AI classification of an unknown sender (versioned; full text in docs/modules/ai.md — keep identical).
 * Sent: sender address, subject, the first 1500 characters of the cleaned body (no quotes/signatures). Only for
 * senders without a rule that are not known candidates. System = shared-format instructions only (no dates, ids,
 * names). Pure: build/parse need no DB or HTTP (the ai:experiment harness uses them on fixtures).
 */
final class MailClassificationPrompt implements AiPromptTemplate
{
    public const string VERSION = 'mail_classify.v4';

    public const int MAX_TOKENS = 1500;

    /** Below this the extracted applicant data is not kept (too unsure to prefill anything). */
    public const float PREFILL_CONFIDENCE = 0.7;

    public const int SUBJECT_LIMIT = 300;

    // prompt cache: prefix < 1024 tokens — the instructions are short; still byte-stable (no dates, ids or names) so
    // the broker's cache_control / automatic caching applies when the provider's minimum allows.
    public const string ROLE = "Sorter of a recruiting team's incoming e-mail.";

    public const string TASK = 'Classify the unknown sender of the letter (user message).';

    /** @var list<string> */
    public const array RULES = [
        'kind: job_board = job-site notice about an application; candidate = a person writing about a job for themselves; colleague = work/business letter, not an application; newsletter = marketing, digest, service notice; ignore = spam, phishing, bounces.',
        'job_board only with an explicit job-site signal (site name/domain, application notice); parser: work_ua | robota_ua | djinni | generic; otherwise null.',
        'colleague only with an explicit internal/business-relationship cue; missing job signals do not mean colleague. A short/vague letter from an unfamiliar external address → candidate, conf ≤0.5.',
        'conf 0..1, calibrated: short, vague or no identifying signal → ≤0.5; ≥0.85 only without real doubt (applied without a person).',
        "cand only for candidate/job_board about one applicant: the applicant's own name, phone, email, vacancy, copied exactly; otherwise all null.",
    ];

    public const string OUTPUT = '{"kind":str,"parser":str|null,"conf":num,"cand":{"name":str|null,"phone":str|null,"email":str|null,"vacancy":str|null}}';

    public function purpose(): AiPurpose
    {
        return AiPurpose::MailClassification;
    }

    public function version(): string
    {
        return self::VERSION;
    }

    /** Fixture input: {from, subject, body}. */
    public function fromFixture(array $input): AiPrompt
    {
        return self::build(new GmailMessage(
            'fixture',
            Carbon::now(),
            (string) ($input['from'] ?? ''),
            null,
            (string) ($input['subject'] ?? ''),
            (string) ($input['body'] ?? ''),
        ));
    }

    public function skipReason(array $input): ?string
    {
        return self::isEmpty((string) ($input['subject'] ?? ''), (string) ($input['body'] ?? '')) ? 'no_content' : null;
    }

    /** Server-side prefilter: a letter without subject and body is not worth a model call. */
    public static function isEmpty(string $subject, string $body): bool
    {
        return trim($subject) === '' && MailBodyCleaner::clean($body) === '';
    }

    /**
     * Auto-apply guard (round 1 of the experiment: models gave 0.95+ to vague letters): confidence ≥ threshold AND a
     * concrete signal — the rule-based hint for the sender domain agrees with the kind, or applicant contacts were
     * extracted for a candidate/job-board letter. Otherwise the answer stays a suggestion in the queue.
     *
     * @param  array<string, mixed>  $parsed  output of parseJson()
     */
    public static function autoApplicable(string $from, array $parsed): bool
    {
        if ((float) ($parsed['confidence'] ?? 0) < MailClassificationAiHandler::AUTO_APPLY_CONFIDENCE) {
            return false;
        }
        [$hint] = SenderSuggester::suggest(mb_strtolower($from));
        if ($hint !== null && $hint->value === ($parsed['kind'] ?? null)) {
            return true;
        }
        $x = is_array($parsed['extracted'] ?? null) ? $parsed['extracted'] : [];

        return in_array($parsed['kind'] ?? null, ['candidate', 'job_board'], true)
            && (($x['phone'] ?? null) !== null || ($x['email'] ?? null) !== null || ($x['full_name'] ?? null) !== null);
    }

    public function parse(string $text): array
    {
        return self::parseJson(JsonOutput::decode($text) ?? throw InvalidAiOutput::because('not_json'));
    }

    /** Expected: {kind, parser?, auto_apply?: bool, extracted?: {field: value}} (extracted values compared case-insensitively). */
    public function compare(array $parsed, array $expected): array
    {
        $checks = ['kind' => ($parsed['kind'] ?? null) === ($expected['kind'] ?? null)];
        if (array_key_exists('parser', $expected)) {
            $checks['parser'] = ($parsed['parser'] ?? null) === $expected['parser'];
        }
        if (array_key_exists('auto_apply', $expected)) {
            $checks['auto_apply'] = self::autoApplicable((string) ($expected['from'] ?? ''), $parsed) === $expected['auto_apply'];
        }
        foreach ((array) ($expected['extracted'] ?? []) as $field => $value) {
            $got = is_array($parsed['extracted'] ?? null) ? ($parsed['extracted'][$field] ?? null) : null;
            $checks['extracted.'.$field] = mb_strtolower(trim((string) $got)) === mb_strtolower(trim((string) $value));
        }

        return $checks;
    }

    public static function build(GmailMessage $message): AiPrompt
    {
        return new AiPrompt(
            purpose: AiPurpose::MailClassification,
            version: self::VERSION,
            system: PromptBuilder::system(self::ROLE, self::TASK, self::RULES, self::OUTPUT),
            user: PromptBuilder::data([
                'from' => (string) $message->fromEmail,
                'subject' => PromptBuilder::cut($message->subject, self::SUBJECT_LIMIT),
                'body' => MailBodyCleaner::clean($message->text),
            ]),
            maxTokens: self::MAX_TOKENS,
            temperature: 0.0,
            schema: self::schema(),
            schemaName: 'mail_classification',
        );
    }

    /**
     * Validated answer in internal names: {kind, parser (job_board only), confidence 0..1, extracted|null}.
     *
     * @param  array<string, mixed>  $json
     * @return array{kind: string, parser: string|null, confidence: float, extracted: array<string, string|null>|null}
     *
     * @throws InvalidAiOutput
     */
    public static function parseJson(array $json): array
    {
        $kind = SenderKind::from(JsonOutput::oneOf($json, 'kind', SenderKind::values()));
        $hint = $json['parser'] ?? null;
        if ($hint !== null && (! is_string($hint) || ParserKey::tryFrom($hint) === null)) {
            throw InvalidAiOutput::because('parser_not_allowed');
        }
        $confidence = JsonOutput::number($json, 'conf', 0.0, 1.0);
        $cand = $json['cand'] ?? null;
        if (! is_array($cand)) {
            throw InvalidAiOutput::because('cand_not_object');
        }
        /** @var array<string, mixed> $cand */
        $fields = [
            'full_name' => JsonOutput::text($cand, 'name', 150),
            'phone' => JsonOutput::text($cand, 'phone', 40),
            'email' => JsonOutput::text($cand, 'email', 150),
            'vacancy_title' => JsonOutput::text($cand, 'vacancy', 200),
        ];
        $applicant = in_array($kind, [SenderKind::Candidate, SenderKind::JobBoard], true) && $confidence >= self::PREFILL_CONFIDENCE;

        return [
            'kind' => $kind->value,
            'parser' => $kind === SenderKind::JobBoard ? (is_string($hint) ? $hint : ParserKey::Generic->value) : null,
            'confidence' => round($confidence, 3),
            'extracted' => $applicant && array_filter($fields) !== [] ? $fields : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['kind', 'parser', 'conf', 'cand'],
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => SenderKind::values()],
                'parser' => ['type' => ['string', 'null'], 'enum' => [...ParserKey::values(), null]],
                'conf' => ['type' => 'number'],
                'cand' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['name', 'phone', 'email', 'vacancy'],
                    'properties' => ['name' => $nullableString, 'phone' => $nullableString, 'email' => $nullableString, 'vacancy' => $nullableString],
                ],
            ],
        ];
    }
}
