<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Core\Services\NavBadgeService;

/** Reads GET /api/nav/badges for a user (cache dropped first, so the number reflects the data right now). */
trait NavBadgeAssertions
{
    /** @return array<string, int> */
    protected function badgesOf(User $user): array
    {
        $this->app->make(NavBadgeService::class)->forget($user);

        /** @var array<string, int> */
        return $this->actingAs($user)->getJson('/api/nav/badges')->assertOk()->json('data') ?? [];
    }

    /**
     * The badge equals the number of rows the target page's list endpoint returns ($rowFilter: the rows the page
     * marks as needing action, e.g. status "waiting"; "meta.total" as $countPath reads a paginator total).
     *
     * @param  (callable(array<string, mixed>): bool)|null  $rowFilter
     */
    protected function assertBadgeMatchesList(User $user, string $key, string $listUrl, int $expected, string $countPath = 'data', ?callable $rowFilter = null): void
    {
        $list = $this->actingAs($user)->getJson($listUrl)->assertOk();
        $rows = (array) $list->json($countPath);
        $listed = $countPath === 'meta.total' ? (int) $list->json('meta.total') : count($rowFilter === null ? $rows : array_filter($rows, $rowFilter));
        $this->assertSame($expected, $listed, "list $listUrl");
        $this->assertSame($expected, $this->badgesOf($user)[$key] ?? null, "badge $key");
    }
}
