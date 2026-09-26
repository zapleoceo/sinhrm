<?php

declare(strict_types=1);

use App\Modules\Assets\Providers\AssetsServiceProvider;
use App\Modules\Auth\Providers\AuthServiceProvider;
use App\Modules\Channels\Providers\ChannelsServiceProvider;
use App\Modules\Core\Providers\CoreServiceProvider;
use App\Modules\Desk\Providers\DeskServiceProvider;
use App\Modules\Directory\Providers\DirectoryServiceProvider;
use App\Modules\Documents\Providers\DocumentsServiceProvider;
use App\Modules\GoogleWorkspace\Providers\GoogleWorkspaceServiceProvider;
use App\Modules\Integrations\Providers\IntegrationsServiceProvider;
use App\Modules\Knowledge\Providers\KnowledgeServiceProvider;
use App\Modules\MailAgent\Providers\MailAgentServiceProvider;
use App\Modules\Overview\Providers\OverviewServiceProvider;
use App\Modules\People\Providers\PeopleServiceProvider;
use App\Modules\Perform\Providers\PerformServiceProvider;
use App\Modules\Pulse\Providers\PulseServiceProvider;
use App\Modules\Recruiting\Providers\RecruitingServiceProvider;
use App\Modules\Reports\Providers\ReportsServiceProvider;
use App\Modules\SafeSpeak\Providers\SafeSpeakServiceProvider;
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
    PerformServiceProvider::class,
    PulseServiceProvider::class,
    KnowledgeServiceProvider::class,
    DeskServiceProvider::class,
    SafeSpeakServiceProvider::class,
    AssetsServiceProvider::class,
    ReportsServiceProvider::class,
    OverviewServiceProvider::class,
];
