<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\Repositories\AiPromptVersionRepository;

/**
 * Edited prompt versions (admin prompt editor). A prompt's system text is split in two at "\nOUTPUT: ":
 * - the instruction part (ROLE/TASK/RULES) — editable, stored in ai_prompt_versions;
 * - OUTPUT + reference sections (e.g. SCRIPT) — code-owned, so an edit can never break parsing or the JSON schema.
 * When a version is active for the purpose, AiService sends its body instead of the built-in instruction part and
 * records its label (screening.v4-custom-1) in ai_requests.prompt_version. The body must stay byte-stable (prompt
 * caching): dates, times and uuids are rejected on save.
 */
final readonly class PromptOverrides
{
    public const string OUTPUT_MARKER = "\nOUTPUT: ";

    public const int MIN_LENGTH = 40;

    public const int MAX_LENGTH = 6000;

    public function __construct(private AiPromptVersionRepository $versions) {}

    public function apply(AiPrompt $prompt): AiPrompt
    {
        $active = $this->versions->active($prompt->purpose);
        if ($active === null) {
            return $prompt;
        }
        $system = self::withBody($prompt->system, $active->body);

        return $system === null ? $prompt : $prompt->with(version: $active->version, system: $system);
    }

    /** Instruction part of a built system text (everything before OUTPUT), or the whole text when there is no OUTPUT. */
    public static function body(string $system): string
    {
        $at = strpos($system, self::OUTPUT_MARKER);

        return $at === false ? $system : substr($system, 0, $at);
    }

    /** Code-owned tail (OUTPUT + reference sections) without the leading newline; '' when absent. */
    public static function tail(string $system): string
    {
        $at = strpos($system, self::OUTPUT_MARKER);

        return $at === false ? '' : substr($system, $at + 1);
    }

    /** $system with its instruction part replaced by $body; null when $system has no OUTPUT line. */
    public static function withBody(string $system, string $body): ?string
    {
        $at = strpos($system, self::OUTPUT_MARKER);

        return $at === false ? null : self::normalize($body).substr($system, $at);
    }

    /** Line endings → \n, trailing spaces trimmed (the body is compared and cached byte for byte). */
    public static function normalize(string $body): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $body));

        return trim(implode("\n", array_map(rtrim(...), $lines)));
    }

    /**
     * Problems of an edited body (empty list = valid): length, ROLE/TASK/RULES present and in this order (ROLE first),
     * no OUTPUT or other code-owned section, no dates/times/uuids (they would break prompt caching).
     *
     * @return list<string> too_short | too_long | missing_role | missing_task | missing_rules | order |
     *                      output_not_editable | volatile_data
     */
    public static function problems(string $body): array
    {
        $body = self::normalize($body);
        $problems = [];
        $length = mb_strlen($body);
        if ($length < self::MIN_LENGTH) {
            $problems[] = 'too_short';
        }
        if ($length > self::MAX_LENGTH) {
            $problems[] = 'too_long';
        }
        $positions = [];
        foreach (['role' => '/^ROLE: \S/m', 'task' => '/^TASK: \S/m', 'rules' => '/^RULES:[ \t]*$/m'] as $name => $pattern) {
            if (preg_match($pattern, $body, $m, PREG_OFFSET_CAPTURE) === 1) {
                $positions[] = $m[0][1];
            } else {
                $problems[] = 'missing_'.$name;
            }
        }
        if (count($positions) === 3 && ($positions[0] !== 0 || $positions[0] > $positions[1] || $positions[1] > $positions[2])) {
            $problems[] = 'order';
        }
        if (preg_match('/^(OUTPUT|SCRIPT)\s*:/mi', $body) === 1) {
            $problems[] = 'output_not_editable';
        }
        $volatile = '/\b\d{4}-\d{2}-\d{2}\b|\b\d{1,2}[.\/]\d{1,2}[.\/]\d{4}\b|\b\d{1,2}:\d{2}\b|\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i';
        if (preg_match($volatile, $body) === 1) {
            $problems[] = 'volatile_data';
        }

        return $problems;
    }
}
