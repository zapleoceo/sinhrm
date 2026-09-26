<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Contracts\AiProvider;
use App\Modules\Ai\DTO\AiJobRef;
use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\DTO\AiResult;
use App\Modules\Ai\Exceptions\AiException;
use App\Modules\Ai\Support\AiSettingsReader;
use App\Modules\Integrations\Definitions\OpenRouterDefinition;
use App\Modules\Integrations\Services\IntegrationConfigLoader;
use App\Modules\Integrations\Support\OutboundUrlGuard;
use App\Modules\Integrations\Support\SecretScrubber;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Alternative provider (NOT the default): OpenRouter chat completions, synchronous. submit() makes the call and keeps
 * the answer in the job reference, poll() returns it. Model = the AI settings model without the "openrouter/" prefix
 * (the broker naming), or DEFAULT_MODEL when the model is empty. Key: integration "openrouter", secret api_key.
 * Switching: bind AiProvider to this class in AiServiceProvider (docs/modules/ai.md).
 */
final readonly class OpenRouterProvider implements AiProvider
{
    public const string KEY = 'openrouter';

    public const string URL = 'https://openrouter.ai/api/v1/chat/completions';

    public const string DEFAULT_MODEL = 'openai/gpt-5.6-luna';

    /** Synchronous call: it has to fit into the serverless request together with the rest of the work. */
    private const int TIMEOUT_SECONDS = 40;

    public function __construct(
        private Http $http,
        private OutboundUrlGuard $guard,
        private AiSettingsReader $settings,
        private IntegrationConfigLoader $loader,
        private OpenRouterDefinition $definition,
        private SecretScrubber $scrubber,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function submit(AiPrompt $prompt): AiJobRef
    {
        $key = $this->loader->load($this->definition)->secret('api_key');
        if ($key === null) {
            throw AiException::notConfigured();
        }
        $blocked = $this->guard->check(self::URL);
        if ($blocked !== null) {
            throw AiException::provider($blocked);
        }
        $this->scrubber->remember($key);

        $model = $this->settings->read()->model;
        $body = [
            'model' => $model === null ? self::DEFAULT_MODEL : (string) preg_replace('~^openrouter/~', '', $model),
            'messages' => $prompt->messages(),
            'max_tokens' => $prompt->maxTokens,
            'temperature' => $prompt->temperature,
            'usage' => ['include' => true],
        ];
        $format = $prompt->responseFormat();
        if ($format !== null) {
            $body['response_format'] = $format;
        }

        try {
            $response = $this->http->withOptions(['allow_redirects' => false])->timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()->withToken($key)->post(self::URL, $body);
        } catch (Throwable) {
            throw AiException::provider('connection_failed');
        }
        if (! $response->successful()) {
            throw AiException::provider('http_'.$response->status());
        }
        $text = $response->json('choices.0.message.content');
        $result = is_string($text)
            ? AiResult::done(
                text: $text,
                model: is_string($response->json('model')) ? $response->json('model') : null,
                tokensIn: (int) $response->json('usage.prompt_tokens', 0),
                tokensOut: (int) $response->json('usage.completion_tokens', 0),
                tokensCached: (int) $response->json('usage.prompt_tokens_details.cached_tokens', 0),
                costUsd: (float) $response->json('usage.cost', 0),
                finishReason: is_string($response->json('choices.0.finish_reason')) ? $response->json('choices.0.finish_reason') : null,
            )
            : AiResult::error('ai_provider_bad_response');

        return new AiJobRef(self::KEY, (string) ($response->json('id') ?? 'sync'), 0, $result);
    }

    public function poll(AiJobRef $job): AiResult
    {
        // Synchronous provider: a job reference without the answer (e.g. after a restart) cannot be recovered.
        return $job->immediate ?? AiResult::error('ai_provider_not_pollable');
    }
}
