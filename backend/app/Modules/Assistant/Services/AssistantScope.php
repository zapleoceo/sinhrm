<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Services;

use App\Models\User;
use App\Modules\Auth\Contracts\UserRepository;
use App\Modules\Core\Services\ModuleAccess;
use App\Modules\Directory\Contracts\DictionaryRepository;
use App\Modules\Recruiting\Contracts\HiringTeamRepository;

/** Bounded freshness check for polling: a digest only, no personal fields or retained conversation. */
final readonly class AssistantScope
{
    public function __construct(
        private ModuleAccess $modules,
        private DictionaryRepository $directory,
        private UserRepository $users,
        private HiringTeamRepository $team,
    ) {}

    public static function cacheKey(int $requestId): string
    {
        return 'assistant.scope.'.$requestId;
    }

    /** @return array{actor: User, fingerprint: string}|null */
    public function capture(User $user): ?array
    {
        // A request's loaded roles/status/permissions may predate revocation while the broker is running.
        $actor = $this->users->find($user->id);
        if ($actor === null || ! $actor->isActive()) {
            return null;
        }
        // Preserve the request's selected role on the fresh clone; never restore the base superadmin implicitly.
        $selectedRole = $user->activeRole();
        if ($selectedRole !== null && ! in_array($selectedRole, $actor->assignedRoles(), true)) {
            return null;
        }
        $actor->actAs($selectedRole);
        if ($selectedRole !== null && $actor->effectiveRoles() !== [$selectedRole]) {
            return null;
        }
        $settings = $this->modules->refreshSettings();
        if (! $this->modules->allows($actor, 'assistant')) {
            return null;
        }
        $roles = $actor->getRoleNames()->all();
        $permissions = $actor->getAllPermissions()->pluck('name')->all();
        // Fresh query: a loaded User::branches relation may predate an assignment change.
        $branches = $this->directory->activeBranchIdsOfUser($actor->id);
        $modules = $this->modules->allowedKeys($actor);
        // Canonicalize the exact refreshed snapshot used by the access gate and allowedKeys.
        foreach ($settings as $key => $setting) {
            sort($setting['roles']);
            $settings[$key] = $setting;
        }
        ksort($settings);
        $managed = $this->team->managedVacancyIds($actor->id);
        $interviews = $this->team->interviewApplicationIds($actor->id);
        sort($roles);
        sort($permissions);
        sort($branches);
        sort($modules);
        sort($managed);
        sort($interviews);

        return ['actor' => $actor, 'fingerprint' => hash('sha256', (string) json_encode([
            $actor->id, $actor->status->value, $roles, $permissions, $branches, $modules, $managed, $interviews, $settings,
        ]))];
    }

    /** Re-read authority after a wait/cache read; only the digest is retained between HTTP requests. */
    public function matchingActor(User $user, string $fingerprint): ?User
    {
        $scope = $this->capture($user);

        return $scope !== null && hash_equals($fingerprint, $scope['fingerprint']) ? $scope['actor'] : null;
    }
}
