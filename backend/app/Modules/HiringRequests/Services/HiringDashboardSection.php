<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Services;

use App\Models\User;
use App\Modules\HiringRequests\Models\HiringRequest;
use App\Modules\Overview\Contracts\DashboardSection;
use Illuminate\Support\Carbon;

/** Home page block "hiring": {my_approvals: {count, items[≤5]}} — requests waiting for the user's decision. */
final readonly class HiringDashboardSection implements DashboardSection
{
    public const int LIST = 5;

    public function __construct(private HiringRequestService $requests) {}

    public function key(): string
    {
        return 'hiring';
    }

    public function data(User $user, Carbon $now): array
    {
        $inbox = $this->requests->inbox($user);

        return [
            'my_approvals' => [
                'count' => $inbox->count(),
                'items' => array_values($inbox->take(self::LIST)->map(static function (HiringRequest $r) use ($now): array {
                    $step = $r->currentApproval();

                    return [
                        'id' => $r->id,
                        'title' => $r->title,
                        'branch' => $r->branch->name,
                        'headcount' => $r->headcount,
                        'step' => $step?->name,
                        'overdue' => $step?->isOverdue($now) ?? false,
                    ];
                })->all()),
            ],
        ];
    }
}
