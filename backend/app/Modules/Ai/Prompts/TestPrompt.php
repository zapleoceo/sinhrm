<?php

declare(strict_types=1);

namespace App\Modules\Ai\Prompts;

use App\Modules\Ai\Contracts\AiPromptTemplate;
use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Exceptions\InvalidAiOutput;
use App\Modules\Ai\Support\JsonOutput;
use App\Modules\Ai\Support\PromptBuilder;

/**
 * "Test prompt" button: the smallest request that proves key, capability and JSON output work. No personal data.
 * Full text: docs/modules/ai.md.
 */
final class TestPrompt implements AiPromptTemplate
{
    public const string VERSION = 'test.v2';

    // prompt cache: prefix < 1024 tokens — a one-off check, caching is irrelevant here.
    public const string OUTPUT = '{"ok":bool,"reply":str}';

    public function purpose(): AiPurpose
    {
        return AiPurpose::Test;
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function fromFixture(array $input): AiPrompt
    {
        return self::build();
    }

    public function parse(string $text): array
    {
        return self::parseJson(JsonOutput::decode($text) ?? throw InvalidAiOutput::because('not_json'));
    }

    public function compare(array $parsed, array $expected): array
    {
        return ['ok' => ($parsed['ok'] ?? null) === true];
    }

    public static function system(): string
    {
        return PromptBuilder::system('Integration health check.', 'Confirm you work.', ['ok = true; reply = the word "готово".'], self::OUTPUT);
    }

    public static function build(): AiPrompt
    {
        return new AiPrompt(
            purpose: AiPurpose::Test,
            version: self::VERSION,
            system: self::system(),
            user: PromptBuilder::data(['check' => 'ping']),
            // Reasoning models spend tokens before the answer: leave room even for a tiny JSON.
            maxTokens: 1500,
            temperature: 0.0,
            schema: [
                'type' => 'object',
                'properties' => ['ok' => ['type' => 'boolean'], 'reply' => ['type' => 'string']],
                'required' => ['ok', 'reply'],
                'additionalProperties' => false,
            ],
            schemaName: 'health_check',
        );
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{ok: bool, reply: string|null}
     */
    public static function parseJson(array $json): array
    {
        return ['ok' => JsonOutput::bool($json, 'ok'), 'reply' => JsonOutput::text($json, 'reply', 100)];
    }
}
