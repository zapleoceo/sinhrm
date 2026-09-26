<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Exceptions\InvalidAiOutput;

/**
 * A versioned prompt as a pure unit (no DB, no HTTP): input → messages, model text → validated result. Implemented by
 * the prompt class of every purpose and tagged with AiServiceProvider::PROMPTS_TAG, so the offline experiment harness
 * (php artisan ai:experiment) can run any purpose against any broker capability on synthetic fixtures
 * (backend/tests/Fixtures/ai/<purpose>.json).
 */
interface AiPromptTemplate
{
    public function purpose(): AiPurpose;

    /** e.g. script_eval.v1 — stored on every request and result. */
    public function version(): string;

    /**
     * Builds the prompt from a fixture "input" object (the same data the production code passes, as plain JSON).
     *
     * @param  array<string, mixed>  $input
     */
    public function fromFixture(array $input): AiPrompt;

    /**
     * Reason not to call the model at all for this input (e.g. insufficient_data, no_content), or null.
     * Production applies the same check before calling AI.
     *
     * @param  array<string, mixed>  $input
     */
    public function skipReason(array $input): ?string;

    /**
     * Model text → validated, normalized result (the same parsing production uses).
     *
     * @return array<string, mixed>
     *
     * @throws InvalidAiOutput
     */
    public function parse(string $text): array;

    /**
     * Checks a parsed result against a fixture "expected" object (experiment scoring, not used in production).
     *
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $expected
     * @return array<string, bool> check name → passed
     */
    public function compare(array $parsed, array $expected): array;
}
