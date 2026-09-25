<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\Enums\ApplicationStatus;
use App\Modules\Recruiting\Models\Application;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/** "Stale" = an active application without a real contact (any channel except system) for N days. */
final readonly class StalenessService
{
    /** Default threshold used by the board highlighting and GET /api/recruiting/stale. */
    public const int DEFAULT_DAYS = 3;

    public const int LIMIT = 200;

    public function __construct(private ApplicationRepository $applications, private RecruitingScope $scope) {}

    /** @return Collection<int, Application> */
    public function stale(User $actor, int $days = self::DEFAULT_DAYS): Collection
    {
        return $this->applications->stale($this->scope->for($actor), self::threshold($days), self::LIMIT);
    }

    public static function threshold(int $days = self::DEFAULT_DAYS): Carbon
    {
        return Carbon::now()->subDays($days);
    }

    public static function isStale(Application $application, int $days = self::DEFAULT_DAYS): bool
    {
        $last = $application->lastActivityAt();

        return $application->status === ApplicationStatus::Active && $last !== null && $last->lt(self::threshold($days));
    }
}
