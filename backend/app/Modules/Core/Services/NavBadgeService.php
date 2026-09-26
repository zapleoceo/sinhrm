<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Models\User;
use App\Modules\Core\Contracts\NavBadgeProvider;
use Illuminate\Contracts\Cache\Repository as Cache;

/** Collects sidebar counters from every NavBadgeProvider; cached per user for a short time (the UI polls every minute). */
final readonly class NavBadgeService
{
    public const int TTL_SECONDS = 30;

    /** @param  iterable<NavBadgeProvider>  $providers */
    public function __construct(private iterable $providers, private Cache $cache) {}

    /**
     * @return array<string, int>
     *
     * @phpstan-impure
     */
    public function for(User $user): array
    {
        /** @var array<string, int> */
        return $this->cache->remember($this->key($user), self::TTL_SECONDS, function () use ($user): array {
            $badges = [];
            foreach ($this->providers as $provider) {
                $badges = $provider->badges($user) + $badges;
            }
            ksort($badges);

            return $badges;
        });
    }

    public function forget(User $user): void
    {
        $this->cache->forget($this->key($user));
    }

    /** Per user and per role set: a role change shows the right items at once instead of after the TTL. */
    private function key(User $user): string
    {
        $roles = $user->getRoleNames()->map(static fn (mixed $r): string => (string) $r)->all();
        sort($roles);

        return 'nav-badges:'.$user->id.':'.substr(sha1(implode(',', $roles).'|'.(int) $user->safe_speak_handler), 0, 12);
    }
}
