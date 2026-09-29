<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Ai;

use App\Modules\Ai\Contracts\AiResultHandler;
use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Models\AiRequest;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Keeps a vacancy text draft in the cache for an hour (also when the ai.poll job finished it later), so the form can
 * pick it up by the request id. Nothing is written to the vacancy: the recruiter decides what to keep.
 */
final readonly class VacancyTextHandler implements AiResultHandler
{
    public const int TTL = 3600;

    public function __construct(private Cache $cache) {}

    public static function cacheKey(int $requestId): string
    {
        return 'recruiting.vacancy_text.'.$requestId;
    }

    public function purpose(): AiPurpose
    {
        return AiPurpose::VacancyText;
    }

    public function parse(array $json, AiRequest $request): array
    {
        return VacancyTextPrompt::parseJson($json);
    }

    public function apply(AiRequest $request, array $data): void
    {
        $this->cache->put(self::cacheKey($request->id), ['text' => $data['text'] ?? null], self::TTL);
    }

    public function failed(AiRequest $request, string $error): void
    {
        $this->cache->put(self::cacheKey($request->id), ['error' => $error], self::TTL);
    }

    /** The facts are not kept, so a deferred invalid answer is not retried. */
    public function rebuild(AiRequest $request): ?AiPrompt
    {
        return null;
    }
}
