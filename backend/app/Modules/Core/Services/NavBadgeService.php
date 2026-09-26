<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Models\User;
use App\Modules\Core\Contracts\NavBadgeProvider;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Collects sidebar counters from every NavBadgeProvider; cached per user for a short time (the UI polls every minute).
 * Providers of modules the user cannot open (switched off / role not allowed, modules-access.md) are skipped.
 */
final readonly class NavBadgeService
{
    public const int TTL_SECONDS = 30;

    /** @param  iterable<NavBadgeProvider>  $providers */
    public function __construct(
        private iterable $providers,
        private Cache $cache,
        private ModuleAccess $access,
        private ModuleRegistry $modules,
    ) {}

    /**
     * @return array<string, int>
     *
     * @phpstan-impure
     */
    public function for(User $user): array
    {
        $allowed = $this->access->allowedKeys($user);

        /** @var array<string, int> */
        return $this->cache->remember($this->key($user, $allowed), self::TTL_SECONDS, function () use ($user, $allowed): array {
            $badges = [];
            foreach ($this->providers as $provider) {
                $module = $this->modules->forClass($provider::class);
                if ($module !== null && ! in_array($module->key, $allowed, true)) {
                    continue;
                }
                $badges = $provider->badges($user) + $badges;
            }
            ksort($badges);

            return $badges;
        });
    }

    public function forget(User $user): void
    {
        $this->cache->forget($this->key($user, $this->access->allowedKeys($user)));
    }

    /**
     * Per user, role set and available modules: a role change or a module switch shows the right items at once.
     *
     * @param  list<string>  $allowed
     */
    private function key(User $user, array $allowed): string
    {
        $roles = $user->getRoleNames()->map(static fn (mixed $r): string => (string) $r)->all();
        sort($roles);

        return 'nav-badges:'.$user->id.':'.substr(sha1(implode(',', $roles).'|'.(int) $user->safe_speak_handler.'|'.implode(',', $allowed)), 0, 12);
    }
}
