<?php

declare(strict_types=1);

namespace App\Modules\Channels\Providers;

use App\Modules\Channels\Adapters\BinotelAdapter;
use App\Modules\Channels\Adapters\PhonetAdapter;
use App\Modules\Channels\Adapters\RingostatAdapter;
use App\Modules\Channels\Adapters\TelegramBusinessAdapter;
use App\Modules\Channels\Adapters\ViberAdapter;
use App\Modules\Channels\Adapters\WhatsappCloudAdapter;
use App\Modules\Channels\Support\ChannelRegistry;
use App\Modules\Core\Support\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/** Channels: provider webhooks → timeline, sending from the card, channel admin. Routes at the /api root. */
final class ChannelsServiceProvider extends ModuleServiceProvider
{
    protected string $moduleIcon = 'forum';

    protected string $moduleGroup = 'recruiting';

    public const string ADAPTERS_TAG = 'channels.adapters';

    public const string WEBHOOK_LIMITER = 'channel-webhooks';

    /** Webhook requests per minute per integration and source IP (providers burst on reconnects). */
    public const int WEBHOOKS_PER_MINUTE = 300;

    /** New channel = one adapter class + one line here (+ its IntegrationDefinition). */
    public const array ADAPTERS = [
        TelegramBusinessAdapter::class,
        WhatsappCloudAdapter::class,
        ViberAdapter::class,
        PhonetAdapter::class,
        RingostatAdapter::class,
        BinotelAdapter::class,
    ];

    public function register(): void
    {
        $this->app->tag(self::ADAPTERS, self::ADAPTERS_TAG);
        $this->app->singleton(ChannelRegistry::class, fn (Application $app): ChannelRegistry => new ChannelRegistry(
            $app->tagged(self::ADAPTERS_TAG),
        ));
    }

    public function boot(): void
    {
        parent::boot();

        RateLimiter::for(self::WEBHOOK_LIMITER, fn (Request $request): Limit => Limit::perMinute(self::WEBHOOKS_PER_MINUTE)
            ->by($request->path().'|'.$request->ip()));

        Route::pattern('channelKey', '[a-z0-9_]+');
        Route::bind('channelKey', fn (string $key) => $this->app->make(ChannelRegistry::class)->get($key));
    }
}
