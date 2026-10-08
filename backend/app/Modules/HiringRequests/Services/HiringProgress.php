<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Services;

use App\Modules\HiringRequests\Contracts\HiringRequestRepository;
use App\Modules\HiringRequests\Models\HiringRequest;

/** Hiring progress of requests with a linked vacancy: hires of the vacancy against the requested headcount. */
final readonly class HiringProgress
{
    public function __construct(private HiringRequestRepository $requests) {}

    /**
     * {vacancy_status, hired, headcount, percent} by request id; one query for all the vacancies.
     *
     * @param  iterable<HiringRequest>  $requests
     * @return array<int, array{vacancy_status: string|null, hired: int, headcount: int, percent: int}>
     */
    public function of(iterable $requests): array
    {
        $list = [];
        $vacancyIds = [];
        foreach ($requests as $r) {
            $list[] = $r;
            if ($r->vacancy_id !== null) {
                $vacancyIds[] = $r->vacancy_id;
            }
        }
        $hires = $this->requests->hiresByVacancy($vacancyIds);
        $out = [];
        foreach ($list as $r) {
            $hired = $r->vacancy_id === null ? 0 : ($hires[$r->vacancy_id] ?? 0);
            $out[$r->id] = [
                'vacancy_status' => $r->vacancy?->status->value,
                'hired' => $hired,
                'headcount' => $r->headcount,
                'percent' => $r->headcount > 0 ? (int) min(100, round($hired / $r->headcount * 100)) : 0,
            ];
        }

        return $out;
    }
}
