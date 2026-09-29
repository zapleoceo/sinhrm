<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Policies;

use App\Models\User;
use App\Modules\Recruiting\Models\VacancyTemplate;
use App\Modules\Recruiting\Services\RecruitingScope;

/**
 * Templates are shared: every recruiting writer lists and uses them. Renaming or deleting one is for its author
 * (while still a writer) and for admins (superadmin/admin); legacy templates without an author — admins only.
 */
final readonly class VacancyTemplatePolicy
{
    public function __construct(private RecruitingScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $this->scope->canWrite($user);
    }

    public function create(User $user): bool
    {
        return $this->scope->canWrite($user);
    }

    public function update(User $user, VacancyTemplate $template): bool
    {
        return $this->canManage($user, $template);
    }

    public function delete(User $user, VacancyTemplate $template): bool
    {
        return $this->canManage($user, $template);
    }

    private function canManage(User $user, VacancyTemplate $template): bool
    {
        if ($this->scope->canManage($user)) {
            return true;
        }

        return $template->created_by !== null && $template->created_by === $user->id && $this->scope->canWrite($user);
    }
}
