<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Ai\Contracts\AiRequestRepository;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Exceptions\AiException;
use App\Modules\Ai\Models\AiRequest;
use App\Modules\Ai\Services\AiService;
use App\Modules\Directory\Contracts\DictionaryRepository;
use App\Modules\Directory\Enums\DictionaryType;
use App\Modules\Recruiting\Ai\VacancyTextHandler;
use App\Modules\Recruiting\Ai\VacancyTextPrompt;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * «Створити з ШІ» in the vacancy form: goes through AiService (gates, daily caps, deferral + ai.poll). The answer is
 * only a draft for the form; nothing is saved to the vacancy. A deferred draft is picked up by its request id, only by
 * the user who asked for it.
 */
final readonly class VacancyTextService
{
    /** Synchronous wait for the draft; longer answers are polled by the form. */
    public const int WAIT_SECONDS = 20;

    public function __construct(
        private AiService $ai,
        private AiRequestRepository $requests,
        private Cache $cache,
        private DictionaryRepository $dictionaries,
    ) {}

    /**
     * @param  array{section: string, title: string, category_id: int|null, branch_id: int|null, employment_type: string|null, experience_level: string|null}  $facts
     * @return array{status: string, request_id: int, text: string|null, error: string|null}
     *
     * @throws AiException ai_disabled | ai_not_configured | ai_purpose_disabled | ai_budget_exceeded
     */
    public function generate(User $actor, array $facts): array
    {
        $category = $facts['category_id'] === null ? null : $this->dictionaries->find(DictionaryType::VacancyCategories, $facts['category_id']);
        $branch = $facts['branch_id'] === null ? null : $this->dictionaries->find(DictionaryType::Branches, $facts['branch_id']);
        $outcome = $this->ai->run(VacancyTextPrompt::build([
            'section' => $facts['section'],
            'title' => $facts['title'],
            'category' => $category?->name,
            'branch' => $branch?->name,
            'employment_type' => $facts['employment_type'],
            'experience' => $facts['experience_level'],
        ]), null, null, ['user_id' => $actor->id], self::WAIT_SECONDS);

        return [
            'status' => $outcome->status,
            'request_id' => $outcome->requestId,
            'text' => is_string($outcome->data['text'] ?? null) ? $outcome->data['text'] : null,
            'error' => $outcome->error,
        ];
    }

    /**
     * @return array{status: string, request_id: int, text: string|null, error: string|null}
     *
     * @throws ModelNotFoundException<AiRequest> when the request is not a vacancy draft of this user
     */
    public function poll(User $actor, int $requestId): array
    {
        $request = $this->requests->find($requestId);
        if ($request === null || $request->purpose !== AiPurpose::VacancyText || ($request->meta['user_id'] ?? null) !== $actor->id) {
            throw (new ModelNotFoundException)->setModel(AiRequest::class, [$requestId]);
        }
        $this->ai->refresh($request);
        $cached = $this->cache->get(VacancyTextHandler::cacheKey($requestId));
        $cached = is_array($cached) ? $cached : [];
        $text = is_string($cached['text'] ?? null) ? $cached['text'] : null;
        $error = is_string($cached['error'] ?? null) ? $cached['error'] : null;

        return [
            'status' => $text !== null ? 'done' : ($error !== null ? 'failed' : 'deferred'),
            'request_id' => $requestId,
            'text' => $text,
            'error' => $error,
        ];
    }
}
