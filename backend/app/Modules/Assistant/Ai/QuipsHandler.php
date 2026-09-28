<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Ai;

use App\Modules\Ai\Contracts\AiResultHandler;
use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Exceptions\InvalidAiOutput;
use App\Modules\Ai\Models\AiRequest;
use App\Modules\Assistant\Services\AssistantQuipService;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Validates a batch of one-liners and stores it in the cache under its situation + language (from the request
 * meta), also when the batch was finished later by the ai.poll job.
 */
final readonly class QuipsHandler implements AiResultHandler
{
    public function __construct(private Cache $cache) {}

    public function purpose(): AiPurpose
    {
        return AiPurpose::AssistantQuips;
    }

    /** @return array{jokes: list<string>} */
    public function parse(array $json, AiRequest $request): array
    {
        $raw = is_array($json['jokes'] ?? null) ? $json['jokes'] : throw InvalidAiOutput::because('jokes_not_array');
        $jokes = [];
        foreach ($raw as $joke) {
            $joke = is_string($joke) ? trim($joke, " \t\n\r\"«»") : '';
            if ($joke !== '' && mb_strlen($joke) <= QuipsPrompt::MAX_LENGTH && ! in_array($joke, $jokes, true)) {
                $jokes[] = $joke;
            }
        }
        if ($jokes === []) {
            throw InvalidAiOutput::because('no_jokes');
        }

        return ['jokes' => array_slice($jokes, 0, QuipsPrompt::COUNT)];
    }

    public function apply(AiRequest $request, array $data): void
    {
        $situation = $request->meta['situation'] ?? null;
        $locale = $request->meta['locale'] ?? null;
        if (is_string($situation) && is_string($locale)) {
            $this->cache->put(AssistantQuipService::cacheKey($situation, $locale), $data['jokes'], AssistantQuipService::TTL);
        }
    }

    public function failed(AiRequest $request, string $error): void {}

    public function rebuild(AiRequest $request): AiPrompt
    {
        $situation = $request->meta['situation'] ?? 'fall';
        $locale = $request->meta['locale'] ?? 'uk';

        return QuipsPrompt::build(is_string($situation) ? $situation : 'fall', is_string($locale) ? $locale : 'uk');
    }
}
