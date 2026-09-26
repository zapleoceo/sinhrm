<?php

declare(strict_types=1);

namespace App\Modules\Perform\Services;

use App\Models\User;
use App\Modules\People\Services\PeopleScope;
use App\Modules\Perform\DTO\PerformViewer;
use App\Modules\Perform\Exceptions\PerformException;

/** Builds the PerformViewer of a request from People's access model (admin = HR, manager = org subtree). */
final readonly class PerformAccess
{
    public function __construct(private PeopleScope $scope) {}

    public function viewer(User $user): PerformViewer
    {
        $ctx = $this->scope->for($user);
        $self = $ctx->selfId === null ? null : $this->scope->employeeOf($user);

        return new PerformViewer($user->id, $ctx, $self?->department_id);
    }

    public function isAdmin(User $user): bool
    {
        return $this->scope->isAdmin($user);
    }

    /** @throws PerformException no_employee */
    public static function requireSelf(PerformViewer $viewer): int
    {
        return $viewer->selfId() ?? throw PerformException::noEmployee();
    }
}
