<?php

declare(strict_types=1);

namespace App\Modules\Ai\DTO;

use App\Modules\Ai\Enums\AiPurpose;

/**
 * One chat request. Prompt-caching order (docs/modules/ai.md): the STABLE part goes into $system and is sent first
 * (instructions + JSON schema + per-version reference data such as a script); everything that changes per call
 * (transcript, e-mail, candidate materials) goes into $user, last. $system must never contain dates, ids or names.
 */
final readonly class AiPrompt
{
    /** @param  array<string, mixed>|null  $schema  JSON schema of the answer (sent as response_format json_schema) */
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
    ) {}

    public function withCapability(string $capability): self
    {
        return new self($this->purpose, $this->version, $this->system, $this->user, $this->maxTokens, $this->temperature, $this->schema, $this->schemaName, $capability);
    }

    /** Same request with another instruction text/version (prompt editor) or purpose (prompt trial). */
    public function with(?string $version = null, ?string $system = null, ?AiPurpose $purpose = null): self
    {
        return new self($purpose ?? $this->purpose, $version ?? $this->version, $system ?? $this->system, $this->user, $this->maxTokens, $this->temperature, $this->schema, $this->schemaName, $this->capability);
    }

    /** @return list<array{role: string, content: string}> */
    public function messages(): array
    {
        return [
            ['role' => 'system', 'content' => $this->system],
            ['role' => 'user', 'content' => $this->user],
        ];
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
