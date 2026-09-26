<?php

declare(strict_types=1);

namespace App\Modules\Ai\Providers;

use App\Modules\Ai\Console\AiExperimentCommand;
use App\Modules\Ai\Contracts\AiProvider;
use App\Modules\Ai\Contracts\AiRequestRepository;
use App\Modules\Ai\Prompts\PromptTrialHandler;
use App\Modules\Ai\Prompts\TestPrompt;
use App\Modules\Ai\Prompts\TestPromptHandler;
use App\Modules\Ai\Repositories\EloquentAiRequestRepository;
use App\Modules\Ai\Services\AiBrokerProvider;
use App\Modules\Ai\Services\AiPollJob;
use App\Modules\Ai\Support\AiHandlerRegistry;
use App\Modules\Ai\Support\AiPromptRegistry;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Core\Support\ModuleServiceProvider;
use Illuminate\Contracts\Foundation\Application;

/**
 * AI: provider gateway (AI Broker by default), budgets, deferred completion (ai.poll), the admin status/test and the
 * offline experiment harness (ai:experiment). Purposes live in the modules that own the data: they tag an
 * AiResultHandler (HANDLERS_TAG) and an AiPromptTemplate (PROMPTS_TAG) and call AiService. Docs: docs/modules/ai.md.
 */
final class AiServiceProvider extends ModuleServiceProvider
{
    protected string $moduleIcon = 'smart_toy';

    protected string $moduleGroup = 'admin';

    /** Every route of the module is behind the manage-integrations gate (superadmin only). */
    protected ?array $defaultRoles = [UserRole::Superadmin];

    /** Container tag of AiResultHandler classes (one per AiPurpose). */
    public const string HANDLERS_TAG = 'ai.handlers';

    /** Container tag of AiPromptTemplate classes (one per AiPurpose). */
    public const string PROMPTS_TAG = 'ai.prompts';

    protected string $prefix = 'ai';

    public function register(): void
    {
        // Switch provider here: OpenRouterProvider::class is the alternative (docs/modules/ai.md).
        $this->app->bind(AiProvider::class, AiBrokerProvider::class);
        $this->app->bind(AiRequestRepository::class, EloquentAiRequestRepository::class);
        $this->app->tag([TestPromptHandler::class, PromptTrialHandler::class], self::HANDLERS_TAG);
        $this->app->tag([TestPrompt::class], self::PROMPTS_TAG);
        $this->app->bind(AiHandlerRegistry::class, fn (Application $app): AiHandlerRegistry => new AiHandlerRegistry(
            $app->tagged(self::HANDLERS_TAG),
        ));
        $this->app->bind(AiPromptRegistry::class, fn (Application $app): AiPromptRegistry => new AiPromptRegistry(
            $app->tagged(self::PROMPTS_TAG),
        ));
        $this->app->tag([AiPollJob::class], ScheduledJob::class);
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([AiExperimentCommand::class]);
        }
    }
}
