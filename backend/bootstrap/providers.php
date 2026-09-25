<?php

declare(strict_types=1);

use App\Modules\Core\Providers\CoreServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    // Domain modules (app/Modules/*). Add each new module provider here.
    CoreServiceProvider::class,
];
