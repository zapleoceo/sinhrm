<?php

declare(strict_types=1);

use App\Modules\Auth\Providers\AuthServiceProvider;
use App\Modules\Channels\Providers\ChannelsServiceProvider;
use App\Modules\Core\Providers\CoreServiceProvider;
use App\Modules\Directory\Providers\DirectoryServiceProvider;
use App\Modules\Documents\Providers\DocumentsServiceProvider;
use App\Modules\GoogleWorkspace\Providers\GoogleWorkspaceServiceProvider;
use App\Modules\Integrations\Providers\IntegrationsServiceProvider;
use App\Modules\MailAgent\Providers\MailAgentServiceProvider;
use App\Modules\Overview\Providers\OverviewServiceProvider;
use App\Modules\People\Providers\PeopleServiceProvider;
use App\Modules\Recruiting\Providers\RecruitingServiceProvider;
use App\Modules\Scripts\Providers\ScriptsServiceProvider;
use App\Modules\TimeOff\Providers\TimeOffServiceProvider;
use App\Modules\Users\Providers\UsersServiceProvider;
use App\Modules\Workflows\Providers\WorkflowsServiceProvider;
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
    GoogleWorkspaceServiceProvider::class,
    MailAgentServiceProvider::class,
    ChannelsServiceProvider::class,
    PeopleServiceProvider::class,
    TimeOffServiceProvider::class,
    DocumentsServiceProvider::class,
    WorkflowsServiceProvider::class,
    OverviewServiceProvider::class,
];
