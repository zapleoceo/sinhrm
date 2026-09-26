<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Controllers;

use App\Models\User;
use App\Modules\Perform\DTO\PerformViewer;
use App\Modules\Perform\Services\PerformAccess;
use Illuminate\Http\Request;

/** Shared bits of Perform controllers: the signed-in user and their PerformViewer. */
abstract class PerformController
{
    public function __construct(protected readonly PerformAccess $access) {}

    protected function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }

    protected function viewer(Request $request): PerformViewer
    {
        return $this->access->viewer($this->actor($request));
    }
}
