<?php

declare(strict_types=1);

namespace App\Modules\Core\Transfer;

use Illuminate\Support\ServiceProvider;

/**
 * One-off cutover tool `db:transfer-to-mysql` (HRM-2/HRM-39, docs/guides/mysql-cutover.md). The whole tool lives in
 * this directory, including its config (`db_transfer.*`). REMOVE AFTER CUTOVER: delete app/Modules/Core/Transfer,
 * tests/{Unit,Feature}/Core/Transfer, .github/workflows/mysql-data-transfer.yml and the register() line in
 * CoreServiceProvider — the checklist is in docs/guides/mysql-cutover.md.
 */
final class TransferServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config.php', 'db_transfer');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([TransferToMysqlCommand::class]);
        }
    }
}
