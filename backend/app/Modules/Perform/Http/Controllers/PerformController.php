<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Controllers;

use App\Modules\Core\Http\Concerns\ResolvesActor;
use App\Modules\Perform\DTO\PerformViewer;
use App\Modules\Perform\Services\PerformAccess;
use Illuminate\Http\Request;

/** Shared bits of Perform controllers: the signed-in user and their PerformViewer. */
abstract class PerformController
{
    use ResolvesActor {
        actor as protected;
    }

    public function __construct(protected readonly PerformAccess $access) {}

    protected function viewer(Request $request): PerformViewer
    {
        return $this->access->viewer($this->actor($request));
    }
}
