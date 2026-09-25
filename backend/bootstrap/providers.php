<?php

declare(strict_types=1);

use App\Modules\Auth\Providers\AuthServiceProvider;
use App\Modules\Core\Providers\CoreServiceProvider;
use App\Modules\Directory\Providers\DirectoryServiceProvider;
use App\Modules\Integrations\Providers\IntegrationsServiceProvider;
use App\Modules\Overview\Providers\OverviewServiceProvider;
use App\Modules\Recruiting\Providers\RecruitingServiceProvider;
use App\Modules\Scripts\Providers\ScriptsServiceProvider;
use App\Modules\Users\Providers\UsersServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    // Domain modules (app/Modules/*). Add each new module provider here.
    CoreServiceProvider::class,
    AuthServiceProvider::class,
    UsersServiceProvider::class,
    IntegrationsServiceProvider::class,
    DirectoryServiceProvider::class,
    RecruitingServiceProvider::class,
    ScriptsServiceProvider::class,
    OverviewServiceProvider::class,
];
