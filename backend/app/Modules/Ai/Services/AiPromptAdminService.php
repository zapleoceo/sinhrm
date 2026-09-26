<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Models\User;
use App\Modules\Ai\Contracts\AiRequestRepository;
use App\Modules\Ai\DTO\AiOutcome;
use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Exceptions\AiException;
use App\Modules\Ai\Models\AiPromptVersion;
use App\Modules\Ai\Repositories\AiPromptVersionRepository;
use App\Modules\Ai\Support\AiPromptRegistry;
use App\Modules\Ai\Support\AiSamples;
use App\Modules\Ai\Support\AiSettingsReader;
use App\Modules\Ai\Support\PromptOverrides;
use App\Modules\Integrations\Definitions\AiBrokerDefinition;
use App\Modules\Integrations\Services\IntegrationService;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Admin prompt editor (superadmin): the effective instruction text per purpose, edited versions (save = new active
 * version, rollback, back to built-in), the broker capability of the purpose and "Спробувати" (the draft and the active
 * version on a built-in synthetic sample through AiService — gates and daily caps apply, nothing is applied to data).
 * Audit: author/activator on the row + log lines ai.prompt_*; capability changes go through IntegrationService
 * (integration_logs).
 */
final readonly class AiPromptAdminService
{
    /** Each of the two trial runs waits at most this long (both must fit one 60 s serverless request). */
    public const int TRIAL_WAIT_SECONDS = 20;

    public function __construct(
        private AiPromptRegistry $prompts,
        private AiPromptVersionRepository $versions,
        private PromptOverrides $overrides,
        private AiSettingsReader $settings,
        private IntegrationService $integrations,
        private AiBrokerDefinition $broker,
        private AiService $ai,
        private AiRequestRepository $requests,
    ) {}

    /** @return array<string, mixed> */
    public function show(AiPurpose $purpose): array
    {
        $built = $this->builtin($purpose);
        $builtinBody = PromptOverrides::body($built->system);
        $active = $this->versions->active($purpose);
        $authors = User::query()
            ->whereIn('id', $this->versions->list($purpose)->pluck('author_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        return [
            'purpose' => $purpose->value,
            'builtin_version' => $built->version,
            'builtin_body' => $builtinBody,
            'output' => explode("\n", PromptOverrides::tail($built->system))[0],
            'active_version' => $active?->version,
            'version' => $active === null ? $built->version : $active->version,
            'body' => $active === null ? $builtinBody : $active->body,
            'capability' => $this->settings->read()->capabilityFor($purpose),
            'capabilities' => AiBrokerDefinition::CAPABILITIES,
            'limits' => ['min' => PromptOverrides::MIN_LENGTH, 'max' => PromptOverrides::MAX_LENGTH],
            'versions' => $this->versions->list($purpose)->map(fn (AiPromptVersion $v): array => [
                'id' => $v->id,
                'version' => $v->version,
                'base_version' => $v->base_version,
                'body' => $v->body,
                'is_active' => $v->is_active,
                'author' => $v->author_id === null ? null : $authors->get($v->author_id),
                'created_at' => $v->created_at?->toIso8601String(),
                'activated_at' => $v->activated_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** Saves the edited text as a new version and makes it active. */
    public function save(User $actor, AiPurpose $purpose, string $body): AiPromptVersion
    {
        $base = $this->builtin($purpose)->version;
        $version = $this->versions->create($purpose, $this->versions->nextVersion($purpose, $base), $base, PromptOverrides::normalize($body), $actor->id);
        $this->versions->activate($purpose, $version, $actor->id);
        Log::info('ai.prompt_saved', ['purpose' => $purpose->value, 'version' => $version->version, 'user_id' => $actor->id]);

        return $version;
    }

    /** Rollback/forward to an edited version. */
    public function activate(User $actor, AiPurpose $purpose, AiPromptVersion $version): void
    {
        $this->versions->activate($purpose, $version, $actor->id);
        Log::info('ai.prompt_activated', ['purpose' => $purpose->value, 'version' => $version->version, 'user_id' => $actor->id]);
    }

    /** No edited version active: the code-defined prompt is used again. */
    public function restoreBuiltin(User $actor, AiPurpose $purpose): void
    {
        $this->versions->activate($purpose, null, $actor->id);
        Log::info('ai.prompt_builtin', ['purpose' => $purpose->value, 'user_id' => $actor->id]);
    }

    /** Same field as in the AI Broker card (capability_<purpose>); logged in integration_logs. */
    public function setCapability(User $actor, AiPurpose $purpose, string $capability): void
    {
        $this->integrations->update($actor, $this->broker, [$purpose->capabilitySetting() => $capability], []);
        $this->settings->forget();
    }

    /**
     * Runs the draft and the active version on the built-in sample and returns both parsed results.
     *
     * @return array{draft: array<string, mixed>, active: array<string, mixed>}
     *
     * @throws AiException when AI is unavailable for the purpose or over the daily cap
     */
    public function trial(AiPurpose $purpose, string $body): array
    {
        $this->ai->assertAvailable($purpose);
        $base = $this->builtin($purpose);
        $system = PromptOverrides::withBody($base->system, $body) ?? throw new LogicException('Prompt has no OUTPUT line');
        $capability = $this->settings->read()->capabilityFor($purpose);
        $active = $this->overrides->apply($base);

        $draft = $this->runTrial($base->with(version: mb_substr($base->version.'-draft', 0, 32), system: $system), $purpose, $capability, 'draft');
        try {
            $current = $this->runTrial($active, $purpose, $capability, 'active');
        } catch (AiException $e) {
            $current = ['status' => AiOutcome::FAILED, 'error' => $e->errorCode, 'version' => $active->version, 'data' => null];
        }

        return ['draft' => $draft, 'active' => $current];
    }

    /** @return array<string, mixed> */
    private function runTrial(AiPrompt $prompt, AiPurpose $purpose, string $capability, string $side): array
    {
        $outcome = $this->ai->run(
            $prompt->with(purpose: AiPurpose::PromptTrial)->withCapability($capability),
            meta: ['purpose' => $purpose->value, 'side' => $side],
            waitSeconds: self::TRIAL_WAIT_SECONDS,
        );
        $request = $this->requests->find($outcome->requestId);

        return [
            'status' => $outcome->status,
            'error' => $outcome->error,
            'version' => $prompt->version,
            'data' => $outcome->data,
            'request_id' => $outcome->requestId,
            'tokens_in' => $request->tokens_in ?? 0,
            'tokens_out' => $request->tokens_out ?? 0,
            'tokens_cached' => $request->tokens_cached ?? 0,
            'cost_usd' => $request->cost_usd ?? 0.0,
        ];
    }

    /** Built-in prompt of the purpose on its synthetic sample (the instruction part does not depend on the input). */
    private function builtin(AiPurpose $purpose): AiPrompt
    {
        $template = $this->prompts->find($purpose) ?? throw new LogicException('No prompt template for '.$purpose->value);
        $input = AiSamples::input($purpose) ?? throw new LogicException('No sample for '.$purpose->value);

        return $template->fromFixture($input);
    }
}
