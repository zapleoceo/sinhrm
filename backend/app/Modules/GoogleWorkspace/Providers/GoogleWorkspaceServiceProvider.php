<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Providers;

use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\GoogleWorkspace\Contracts\CalendarClient;
use App\Modules\GoogleWorkspace\Contracts\GmailClient;
use App\Modules\GoogleWorkspace\Contracts\GoogleTokenProvider;
use App\Modules\GoogleWorkspace\Contracts\SheetImportRepository;
use App\Modules\GoogleWorkspace\Contracts\SheetsClient;
use App\Modules\GoogleWorkspace\Repositories\EloquentSheetImportRepository;
use App\Modules\GoogleWorkspace\Services\GoogleCalendarClient;
use App\Modules\GoogleWorkspace\Services\GoogleDashboardNotices;
use App\Modules\GoogleWorkspace\Services\GoogleGmailClient;
use App\Modules\GoogleWorkspace\Services\GoogleSheetsClient;
use App\Modules\GoogleWorkspace\Services\GoogleTokenService;
use App\Modules\GoogleWorkspace\Services\SheetsSyncJob;
use App\Modules\GoogleWorkspace\Support\GoogleOAuthConfig;
use App\Modules\Overview\Contracts\DashboardNotices;
use Illuminate\Contracts\Foundation\Application;

/**
 * Google Workspace: OAuth connection of Gmail / Calendar / Sheets (tokens in the Integrations SecretVault),
 * REST clients (no google/apiclient), meetings from the candidate card, Google Sheets import.
 * Routes under /api/google (routes.php — JSON, routes.web.php — OAuth browser redirects).
 */
final class GoogleWorkspaceServiceProvider extends ModuleServiceProvider
{
    protected string $prefix = 'google';

    public function register(): void
    {
        $this->app->bind(GoogleOAuthConfig::class, fn (Application $app): GoogleOAuthConfig => GoogleOAuthConfig::fromConfig(
            (array) $app->make('config')->get('services.google', []),
        ));
        $this->app->bind(GoogleTokenProvider::class, GoogleTokenService::class);
        $this->app->bind(GmailClient::class, GoogleGmailClient::class);
        $this->app->bind(CalendarClient::class, GoogleCalendarClient::class);
        $this->app->bind(SheetsClient::class, GoogleSheetsClient::class);
        $this->app->bind(SheetImportRepository::class, EloquentSheetImportRepository::class);

        $this->app->tag([SheetsSyncJob::class], ScheduledJob::class);
        $this->app->tag([GoogleDashboardNotices::class], DashboardNotices::class);
    }
}
