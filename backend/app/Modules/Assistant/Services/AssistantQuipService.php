<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Services;

use App\Modules\Ai\Contracts\AiGateway;
use App\Modules\Ai\Contracts\AiRequestRepository;
use App\Modules\Ai\DTO\AiOutcome;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Enums\AiRequestStatus;
use App\Modules\Ai\Exceptions\AiException;
use App\Modules\Assistant\Ai\QuipsPrompt;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Jokes «Стік» says after getting up: one AI batch per situation + language, shared by all users for TTL seconds,
 * so the cost is one request per 6 h per pair, not one per fall. Only one request generates a missing batch at a
 * time: the "busy" cache entry holds the id of that AI request. A slow generation answers "none" (the SPA uses its
 * built-in lines) and the NEXT call polls that same request once (prod 29.09: the free lane needed > 20 s, and the
 * ai.poll cron runs only every 30 min); a failed one is not retried for RETRY_AFTER seconds.
 */
final readonly class AssistantQuipService
{
    public const int TTL = 21600;

    public const int RETRY_AFTER = 600;

    public const int WAIT_SECONDS = 20;

    public function __construct(
        private AiGateway $ai,
        private AiRequestRepository $requests,
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
        if (! $this->ai->available(AiPurpose::AssistantQuips)) {
            return self::none();
        }
        $busy = $this->cache->get($key.'.busy');
        if ($busy !== null) {
            return is_int($busy) ? $this->collect($busy) : self::none();
        }
        if (! $this->cache->add($key.'.busy', true, self::RETRY_AFTER)) {
            return self::none();
        }
        try {
            // The handler caches the batch (also when it is finished later).
            $outcome = $this->ai->run(QuipsPrompt::build($situation, $locale), null, null, ['situation' => $situation, 'locale' => $locale], self::WAIT_SECONDS);
        } catch (AiException) {
            return self::none();
        }
        if ($outcome->isDeferred()) {
            $this->cache->put($key.'.busy', $outcome->requestId, self::RETRY_AFTER);
        }

        return self::from($outcome);
    }

    /**
     * One poll of the pending generation of this pair (finished → the handler has cached it).
     *
     * @return array{jokes: list<string>, source: string}
     */
    private function collect(int $requestId): array
    {
        $request = $this->requests->find($requestId);
        if ($request === null || $request->purpose !== AiPurpose::AssistantQuips || $request->status !== AiRequestStatus::Pending) {
            return self::none();
        }

        return self::from($this->ai->refresh($request));
    }

    /** @return array{jokes: list<string>, source: string} */
    private static function from(AiOutcome $outcome): array
    {
        $jokes = $outcome->isDone() ? ($outcome->data['jokes'] ?? []) : [];
        if (is_array($jokes) && $jokes !== []) {
            /** @var list<string> $jokes */
            return ['jokes' => $jokes, 'source' => 'ai'];
        }

        return self::none();
    }

    /** @return array{jokes: list<string>, source: string} */
    private static function none(): array
    {
        return ['jokes' => [], 'source' => 'none'];
    }
}
