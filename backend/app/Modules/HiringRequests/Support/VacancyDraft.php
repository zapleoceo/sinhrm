<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Support;

use App\Modules\HiringRequests\Models\HiringRequest;
use App\Modules\Recruiting\Enums\VacancyStatus;

/** The vacancy opened from an approved request: its fields copied and a description assembled from the request. */
final class VacancyDraft
{
    /** @return array<string, mixed> */
    public static function from(HiringRequest $r): array
    {
        $lines = array_filter([
            $r->requirements,
            'Кількість позицій: '.$r->headcount,
            $r->desired_start_date === null ? null : 'Бажана дата виходу: '.$r->desired_start_date->format('d.m.Y'),
            $r->salary_min === null && $r->salary_max === null ? null
                : 'Зарплата: '.trim(($r->salary_min ?? '').' – '.($r->salary_max ?? '').' '.($r->currency ?? '')),
            'Заявка на підбір #'.$r->id,
        ]);

        return [
            'title' => $r->title,
            'branch_id' => $r->branch_id,
            'department_id' => $r->department_id,
            'position_id' => $r->position_id,
            'description' => mb_substr(implode("\n\n", $lines), 0, 10000),
            'status' => VacancyStatus::Open->value,
        ];
    }
}
