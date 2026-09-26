<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Providers;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Integrations\Contracts\AiPolicy;
use App\Modules\Integrations\Contracts\HostResolver;
use App\Modules\Integrations\Contracts\IntegrationRepository;
use App\Modules\Integrations\Contracts\SecretVault;
use App\Modules\Integrations\Definitions\AiBrokerDefinition;
use App\Modules\Integrations\Definitions\BinotelDefinition;
use App\Modules\Integrations\Definitions\DeepgramDefinition;
use App\Modules\Integrations\Definitions\GoogleCalendarDefinition;
use App\Modules\Integrations\Definitions\GoogleGmailDefinition;
use App\Modules\Integrations\Definitions\GoogleSheetsDefinition;
use App\Modules\Integrations\Definitions\KepSigningDefinition;
use App\Modules\Integrations\Definitions\MetaLeadAdsDefinition;
use App\Modules\Integrations\Definitions\OpenRouterDefinition;
use App\Modules\Integrations\Definitions\PhonetDefinition;
use App\Modules\Integrations\Definitions\RingostatDefinition;
use App\Modules\Integrations\Definitions\TelegramBusinessDefinition;
use App\Modules\Integrations\Definitions\ViberDefinition;
use App\Modules\Integrations\Definitions\WazzupDefinition;
use App\Modules\Integrations\Definitions\WhatsappCloudDefinition;
use App\Modules\Integrations\Repositories\EloquentIntegrationRepository;
use App\Modules\Integrations\Repositories\EloquentSecretVault;
use App\Modules\Integrations\Services\AiPolicyService;
use App\Modules\Integrations\Support\DnsHostResolver;
use App\Modules\Integrations\Support\IntegrationRegistry;
use App\Modules\Integrations\Support\SecretScrubber;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

final class IntegrationsServiceProvider extends ModuleServiceProvider
{
    protected bool $coreModule = true;

    /** Ability guarding the integrations admin: active superadmin only. */
    public const string MANAGE_INTEGRATIONS = 'manage-integrations';

    /** Container tag of IntegrationDefinition classes. */
    public const string DEFINITIONS_TAG = 'integrations.definitions';

    protected string $prefix = 'integrations';

    public function register(): void
    {
        // New integration = one class + one line here.
        $this->app->tag([
            AiBrokerDefinition::class,
            OpenRouterDefinition::class,
            DeepgramDefinition::class,
            GoogleGmailDefinition::class,
            GoogleCalendarDefinition::class,
            GoogleSheetsDefinition::class,
            TelegramBusinessDefinition::class,
            WhatsappCloudDefinition::class,
            ViberDefinition::class,
            WazzupDefinition::class,
            PhonetDefinition::class,
            RingostatDefinition::class,
            BinotelDefinition::class,
            MetaLeadAdsDefinition::class,
            KepSigningDefinition::class,
        ], self::DEFINITIONS_TAG);

        $this->app->bind(IntegrationRegistry::class, fn (Application $app): IntegrationRegistry => new IntegrationRegistry(
            $app->tagged(self::DEFINITIONS_TAG),
        ));
        $this->app->bind(IntegrationRepository::class, EloquentIntegrationRepository::class);
        $this->app->bind(SecretVault::class, EloquentSecretVault::class);
        $this->app->bind(AiPolicy::class, AiPolicyService::class);
        $this->app->bind(HostResolver::class, DnsHostResolver::class);
        // One per request/app lifetime: it accumulates the secret values seen, for log redaction.
        $this->app->scoped(SecretScrubber::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::define(self::MANAGE_INTEGRATIONS, fn (User $user): bool => $user->isActive()
            && $user->hasRole(UserRole::Superadmin->value));

        Route::pattern('integration', '[a-z0-9_]+');
        Route::bind('integration', fn (string $key) => $this->app->make(IntegrationRegistry::class)->get($key));
    }
}
