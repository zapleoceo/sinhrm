<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Services;

use App\Models\User;
use App\Modules\Core\Services\ModuleAccess;
use App\Modules\Directory\Contracts\DictionaryRepository;

/** Bounded freshness check for polling: a digest only, no personal fields or retained conversation. */
final readonly class AssistantScope
{
    public function __construct(private ModuleAccess $modules, private DictionaryRepository $directory) {}

    public static function cacheKey(int $requestId): string
    {
        return 'assistant.scope.'.$requestId;
    }

    public function fingerprint(User $user): string
    {
        $roles = $user->getRoleNames()->all();
        $permissions = $user->getAllPermissions()->pluck('name')->all();
        // Fresh query: a loaded User::branches relation may predate an assignment change.
        $branches = $this->directory->activeBranchIdsOfUser($user->id);
        $modules = $this->modules->allowedKeys($user);
        sort($roles);
        sort($permissions);
        sort($branches);
        sort($modules);

        return hash('sha256', (string) json_encode([$user->id, $user->status->value, $roles, $permissions, $branches, $modules]));
    }
}
