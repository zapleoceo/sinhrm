<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Services;

use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Exceptions\AiException;
use App\Modules\Ai\Services\AiService;
use App\Modules\Assistant\Ai\QuipsPrompt;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Jokes «Стік» says after getting up: one AI batch per situation + language, shared by all users for TTL seconds,
 * so the cost is one request per 6 h per pair, not one per fall. Only one request generates a missing batch at a
 * time (cache lock); a failed or slow generation answers "none" (the SPA uses its built-in lines) and is not retried
 * for RETRY_AFTER seconds.
 */
final readonly class AssistantQuipService
{
    public const int TTL = 21600;

    public const int RETRY_AFTER = 600;

    public const int WAIT_SECONDS = 20;

    public function __construct(
        private AiService $ai,
        private Cache $cache,
    ) {}

    public static function cacheKey(string $situation, string $locale): string
    {
        return 'assistant.quips.'.QuipsPrompt::VERSION.'.'.$situation.'.'.$locale;
    }

    /** @return array{jokes: list<string>, source: string} */
    public function quips(string $situation, string $locale): array
    {
        $key = self::cacheKey($situation, $locale);
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            /** @var list<string> $cached */
            return ['jokes' => $cached, 'source' => 'ai'];
        }
        if (! $this->ai->available(AiPurpose::AssistantQuips) || ! $this->cache->add($key.'.busy', true, self::RETRY_AFTER)) {
            return ['jokes' => [], 'source' => 'none'];
        }
        try {
            // The handler caches the batch (also when ai.poll finishes it later).
            $outcome = $this->ai->run(QuipsPrompt::build($situation, $locale), null, null, ['situation' => $situation, 'locale' => $locale], self::WAIT_SECONDS);
        } catch (AiException) {
            return ['jokes' => [], 'source' => 'none'];
        }
        $jokes = $outcome->isDone() ? ($outcome->data['jokes'] ?? []) : [];
        if (is_array($jokes) && $jokes !== []) {
            /** @var list<string> $jokes */
            return ['jokes' => $jokes, 'source' => 'ai'];
        }

        return ['jokes' => [], 'source' => 'none'];
    }
}
