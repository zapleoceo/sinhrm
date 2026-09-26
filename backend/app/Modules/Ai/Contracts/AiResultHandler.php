<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Exceptions\InvalidAiOutput;
use App\Modules\Ai\Models\AiRequest;

/**
 * Purpose-specific side of an AI request, implemented by the module that owns the data (Scripts, MailAgent,
 * Recruiting) and tagged in its provider with AiServiceProvider::HANDLERS_TAG. The Ai module never knows the domain.
 *
 * apply() is called exactly once per request (AiService guards the pending → done transition), both for answers that
 * arrive while the caller waits and for deferred ones completed by the ai.poll job; it must still be idempotent
 * against the domain data (e.g. do not overwrite a newer result).
 */
interface AiResultHandler
{
    public function purpose(): AiPurpose;

    /**
     * Validates the decoded JSON of the model and normalizes it.
     *
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     *
     * @throws InvalidAiOutput when the answer does not match the schema
     */
    public function parse(array $json, AiRequest $request): array;

    /** @param  array<string, mixed>  $data  output of parse() */
    public function apply(AiRequest $request, array $data): void;

    /** The request finished without a usable answer (error code as in docs/modules/ai.md). */
    public function failed(AiRequest $request, string $error): void;

    /**
     * The same prompt again, for the one retry after an invalid answer of a deferred request (the prompt itself is
     * never stored). null = cannot be rebuilt (e.g. the e-mail body is not kept) → the request fails.
     */
    public function rebuild(AiRequest $request): ?AiPrompt;
}
