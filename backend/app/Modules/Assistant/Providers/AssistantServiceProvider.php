<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Providers;

use App\Models\User;
use App\Modules\Ai\Providers\AiServiceProvider;
use App\Modules\Assistant\Ai\AssistantChatHandler;
use App\Modules\Assistant\Ai\AssistantVoiceHandler;
use App\Modules\Assistant\Ai\QuipsHandler;
use App\Modules\Assistant\Mcp\SinhrmMcpServer;
use App\Modules\Assistant\Services\McpTokenService;
use App\Modules\Assistant\Support\ToolRegistry;
use App\Modules\Assistant\Tools\ApiGetTool;
use App\Modules\Assistant\Tools\ApiWriteTool;
use App\Modules\Assistant\Tools\FindEndpointsTool;
use App\Modules\Assistant\Tools\OpenPageTool;
use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\Auth\Support\TokenScopes;
use App\Modules\Core\Support\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Helper «Стік»: the in-app chat (/api/assistant/*) and the MCP server for external AI clients (/api/mcp), both built
 * on one tool set (TOOLS_TAG — any module may tag its own AssistantTool). Tools act only with the user's rights.
 * Docs: docs/modules/assistant.md.
 */
final class AssistantServiceProvider extends ModuleServiceProvider
{
    /** Container tag of AssistantTool classes. */
    public const string TOOLS_TAG = 'assistant.tools';

    public const string CHAT_LIMITER = 'assistant-chat';

    public const string MCP_LIMITER = 'assistant-mcp';

    public const string VOICE_LIMITER = 'assistant-voice';

    public const int VOICE_PER_MINUTE = 10;

    public const string MCP_PATH = 'api/mcp';

    public const int CHAT_PER_MINUTE = 20;

    public const int MCP_PER_MINUTE = 60;

    protected string $prefix = 'assistant';

    protected string $moduleIcon = 'emoji_people';

    protected string $moduleGroup = 'services';

    public function register(): void
    {
        $this->app->tag([FindEndpointsTool::class, ApiGetTool::class, ApiWriteTool::class, OpenPageTool::class], self::TOOLS_TAG);
        $this->app->bind(ToolRegistry::class, fn (Application $app): ToolRegistry => new ToolRegistry($app->tagged(self::TOOLS_TAG)));
        $this->app->tag([AssistantChatHandler::class, AssistantVoiceHandler::class, QuipsHandler::class], AiServiceProvider::HANDLERS_TAG);
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->make(TokenScopes::class)->register(self::MCP_PATH, McpTokenService::ABILITY);

        RateLimiter::for(self::CHAT_LIMITER, static fn (Request $request): Limit => Limit::perMinute(self::CHAT_PER_MINUTE)
            ->by('assistant|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for(self::VOICE_LIMITER, static fn (Request $request): Limit => Limit::perMinute(self::VOICE_PER_MINUTE)
            ->by('assistant-voice|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for(self::MCP_LIMITER, static function (Request $request): Limit {
            $user = $request->user();
            $token = $user instanceof User ? $user->currentAccessToken() : null;

            return Limit::perMinute(self::MCP_PER_MINUTE)->by('mcp|'.($token instanceof PersonalAccessToken ? 'token:'.$token->id : 'ip:'.$request->ip()));
        });

        if (! $this->app->routesAreCached()) {
            Mcp::web(self::MCP_PATH, SinhrmMcpServer::class)->middleware([
                'auth:sanctum',
                EnsureUserIsActive::class,
                ...$this->accessMiddleware(),
                'throttle:'.self::MCP_LIMITER,
            ])->name('assistant.mcp');
        }
    }
}
