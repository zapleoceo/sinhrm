<?php

declare(strict_types=1);

namespace App\Modules\Reports\Providers;

use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Reports\Contracts\BuilderRepository;
use App\Modules\Reports\Contracts\ReportDataRepository;
use App\Modules\Reports\Contracts\SavedReportRepository;
use App\Modules\Reports\Datasets\ApplicationsDataset;
use App\Modules\Reports\Datasets\AssetsDataset;
use App\Modules\Reports\Datasets\EmployeesDataset;
use App\Modules\Reports\Datasets\LeaveRequestsDataset;
use App\Modules\Reports\Datasets\TouchpointsDataset;
use App\Modules\Reports\Definitions\AbsencesSummaryReport;
use App\Modules\Reports\Definitions\AgeReport;
use App\Modules\Reports\Definitions\AssetsByStatusReport;
use App\Modules\Reports\Definitions\DeskSlaReport;
use App\Modules\Reports\Definitions\EnpsTrendReport;
use App\Modules\Reports\Definitions\HeadcountReport;
use App\Modules\Reports\Definitions\HiresTerminationsReport;
use App\Modules\Reports\Definitions\LeaveBalancesReport;
use App\Modules\Reports\Definitions\LeaveUsageReport;
use App\Modules\Reports\Definitions\MoodTrendReport;
use App\Modules\Reports\Definitions\OkrProgressReport;
use App\Modules\Reports\Definitions\RecruiterTouchesReport;
use App\Modules\Reports\Definitions\RecruitingFunnelReport;
use App\Modules\Reports\Definitions\RejectReasonsReport;
use App\Modules\Reports\Definitions\ReviewCompletionReport;
use App\Modules\Reports\Definitions\ScriptScoresReport;
use App\Modules\Reports\Definitions\SourceEffectivenessReport;
use App\Modules\Reports\Definitions\TenureReport;
use App\Modules\Reports\Definitions\TimeToHireReport;
use App\Modules\Reports\Definitions\TurnoverReport;
use App\Modules\Reports\Repositories\EloquentSavedReportRepository;
use App\Modules\Reports\Repositories\QueryBuilderRepository;
use App\Modules\Reports\Repositories\QueryReportDataRepository;
use App\Modules\Reports\Support\ReportRegistry;
use Illuminate\Contracts\Foundation\Application;

/**
 * Reports: a registry of ready reports over the other modules (tagged ReportDefinition classes), the custom builder
 * over whitelisted datasets, saved reports, CSV export. Routes: /api/reports/{catalog,builder,saved}.
 */
final class ReportsServiceProvider extends ModuleServiceProvider
{
    public const string REPORTS_TAG = 'reports.definitions';

    public const string DATASETS_TAG = 'reports.datasets';

    protected string $prefix = 'reports';

    public function register(): void
    {
        $this->app->bind(ReportDataRepository::class, QueryReportDataRepository::class);
        $this->app->bind(BuilderRepository::class, QueryBuilderRepository::class);
        $this->app->bind(SavedReportRepository::class, EloquentSavedReportRepository::class);

        // New report = one class + one line here. Gender pay gap is deliberately absent: there is no salary data.
        $this->app->tag([
            HeadcountReport::class,
            HiresTerminationsReport::class,
            TurnoverReport::class,
            TenureReport::class,
            AgeReport::class,
            LeaveUsageReport::class,
            AbsencesSummaryReport::class,
            LeaveBalancesReport::class,
            DeskSlaReport::class,
            AssetsByStatusReport::class,
            RecruitingFunnelReport::class,
            TimeToHireReport::class,
            SourceEffectivenessReport::class,
            RejectReasonsReport::class,
            RecruiterTouchesReport::class,
            ScriptScoresReport::class,
            OkrProgressReport::class,
            ReviewCompletionReport::class,
            EnpsTrendReport::class,
            MoodTrendReport::class,
        ], self::REPORTS_TAG);
        $this->app->tag([
            EmployeesDataset::class,
            ApplicationsDataset::class,
            LeaveRequestsDataset::class,
            TouchpointsDataset::class,
            AssetsDataset::class,
        ], self::DATASETS_TAG);
        $this->app->singleton(ReportRegistry::class, fn (Application $app): ReportRegistry => new ReportRegistry(
            $app->tagged(self::REPORTS_TAG),
            $app->tagged(self::DATASETS_TAG),
        ));
    }
}
