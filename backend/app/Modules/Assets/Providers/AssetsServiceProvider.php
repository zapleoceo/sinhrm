<?php

declare(strict_types=1);

namespace App\Modules\Assets\Providers;

use App\Modules\Assets\Contracts\AssetRepository;
use App\Modules\Assets\Repositories\EloquentAssetRepository;
use App\Modules\Assets\Workflows\CollectAssetsExecutor;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Workflows\Providers\WorkflowsServiceProvider;

/**
 * Assets: types, inventory with unique numbers, assignment history, the Workflows action "collect_assets"
 * (tagged into the Workflows executors). Routes: /api/assets/*.
 */
final class AssetsServiceProvider extends ModuleServiceProvider
{
    protected string $moduleIcon = 'devices';

    protected string $moduleGroup = 'admin';

    /** Inventory, assign/return: superadmin, admin (HR). */
    public const string MANAGE = 'assets-manage';

    protected string $prefix = 'assets';

    public function register(): void
    {
        $this->app->bind(AssetRepository::class, EloquentAssetRepository::class);
        $this->app->tag([CollectAssetsExecutor::class], WorkflowsServiceProvider::EXECUTORS_TAG);
    }

    public function boot(): void
    {
        parent::boot();

        $this->defineRoleGate(self::MANAGE, UserRole::hrStaff());
    }
}
