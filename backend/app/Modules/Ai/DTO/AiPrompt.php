<?php

declare(strict_types=1);

namespace App\Modules\Ai\DTO;

use App\Modules\Ai\Enums\AiPurpose;

/**
 * One chat request. Prompt-caching order (docs/modules/ai.md): the STABLE part goes into $system and is sent first
 * (instructions + JSON schema + per-version reference data such as a script); everything that changes per call
 * (transcript, e-mail, candidate materials) goes into $user, last. $system must never contain dates, ids or names.
 *
 * A conversation (conversation()) instead carries the whole history after the system message and native tools
 * (OpenAI function format; the broker never executes them — the purpose's handler returns the calls to the caller).
 */
final readonly class AiPrompt
{
    /**
     * Answer budget of a conversation turn. Reasoning models of the free lanes (gpt-oss) spend part of max_tokens on
     * hidden reasoning, so the JSON turn needs headroom (docs/modules/ai.md: ≥ 1500 for reasoning models).
     */
    public const int CONVERSATION_MAX_TOKENS = 3000;

    /**
     * @param  array<string, mixed>|null  $schema  JSON schema of the answer (sent as response_format json_schema)
     * @param  list<array<string, mixed>>|null  $history  conversation messages after the system one (user / assistant / tool)
     * @param  list<array<string, mixed>>|null  $tools  native tool definitions [{type: function, function: {name, description, parameters}}]
     */
    public function __construct(
        public AiPurpose $purpose,
        public string $version,
        public string $system,
        public string $user,
        public int $maxTokens = 1500,
        public float $temperature = 0.2,
        public ?array $schema = null,
        public string $schemaName = 'answer',
        /** Broker capability; set by AiService from the settings (or by the ai:experiment command). */
        public ?string $capability = null,
        public ?array $history = null,
        public ?array $tools = null,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $history
     * @param  list<array<string, mixed>>  $tools
     */
    public static function conversation(AiPurpose $purpose, string $version, string $system, array $history, array $tools, int $maxTokens = self::CONVERSATION_MAX_TOKENS, float $temperature = 0.4): self
    {
        return new self($purpose, $version, $system, '', $maxTokens, $temperature, history: $history, tools: $tools === [] ? null : $tools);
    }

    public function withCapability(string $capability): self
    {
        return new self($this->purpose, $this->version, $this->system, $this->user, $this->maxTokens, $this->temperature, $this->schema, $this->schemaName, $capability, $this->history, $this->tools);
    }

    /** Same request with another instruction text/version (prompt editor) or purpose (prompt trial). */
    public function with(?string $version = null, ?string $system = null, ?AiPurpose $purpose = null): self
    {
        return new self($purpose ?? $this->purpose, $version ?? $this->version, $system ?? $this->system, $this->user, $this->maxTokens, $this->temperature, $this->schema, $this->schemaName, $this->capability, $this->history, $this->tools);
    }

    /** @return list<array<string, mixed>> */
    public function messages(): array
    {
        $system = ['role' => 'system', 'content' => $this->system];
        if ($this->history !== null) {
            return [$system, ...$this->history];
        }

        return [$system, ['role' => 'user', 'content' => $this->user]];
    }

    /** @return array<string, mixed>|null */
    public function responseFormat(): ?array
    {
        return $this->schema === null ? null : [
            'type' => 'json_schema',
            'json_schema' => ['name' => $this->schemaName, 'strict' => true, 'schema' => $this->schema],
        ];
    }

    /**
     * Never dump the prompt text: it contains personal data.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['purpose' => $this->purpose->value, 'version' => $this->version];
    }
}
