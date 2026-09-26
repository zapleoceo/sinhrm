<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Http\Requests;

use App\Modules\Directory\Models\Branch;
use App\Modules\Directory\Models\Department;
use App\Modules\Pulse\Enums\WaveSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/pulse/surveys/{id}/waves {starts_at, ends_at, schedule, audience: {branch_ids, department_ids},
 * anonymous (default true), min_group_size (anonymous: ≥ 5, default 5)}. A recurring wave must end before the next
 * one starts (duration ≤ WaveSchedule::maxDays()). Admins.
 */
final class CreateWaveRequest extends FormRequest
{
    public const int ANONYMOUS_MIN_GROUP = 5;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'schedule' => ['required', Rule::enum(WaveSchedule::class)],
            'audience' => ['present', 'array'],
            'audience.branch_ids' => ['sometimes', 'array'],
            'audience.branch_ids.*' => ['integer', Rule::exists(Branch::class, 'id')],
            'audience.department_ids' => ['sometimes', 'array'],
            'audience.department_ids.*' => ['integer', Rule::exists(Department::class, 'id')],
            'anonymous' => ['sometimes', 'boolean'],
            'min_group_size' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($this->boolean('anonymous', true) && $this->integer('min_group_size', self::ANONYMOUS_MIN_GROUP) < self::ANONYMOUS_MIN_GROUP) {
                $validator->errors()->add('min_group_size', 'min_group_size_anonymous');
            }
            $days = Carbon::parse((string) $this->input('starts_at'))->diffInDays(Carbon::parse((string) $this->input('ends_at')));
            if ($days > WaveSchedule::from((string) $this->input('schedule'))->maxDays()) {
                $validator->errors()->add('ends_at', 'wave_too_long');
            }
        }];
    }

    /** @return array{schedule: string, audience: array<string, list<int>>, anonymous: bool, min_group_size: int, starts_at: string, ends_at: string} */
    public function payload(): array
    {
        return [
            'schedule' => (string) $this->string('schedule'),
            'audience' => [
                'branch_ids' => array_values(array_map('intval', (array) $this->input('audience.branch_ids', []))),
                'department_ids' => array_values(array_map('intval', (array) $this->input('audience.department_ids', []))),
            ],
            'anonymous' => $this->boolean('anonymous', true),
            'min_group_size' => $this->integer('min_group_size', self::ANONYMOUS_MIN_GROUP),
            'starts_at' => Carbon::parse((string) $this->input('starts_at'))->toDateTimeString(),
            'ends_at' => Carbon::parse((string) $this->input('ends_at'))->toDateTimeString(),
        ];
    }
}
