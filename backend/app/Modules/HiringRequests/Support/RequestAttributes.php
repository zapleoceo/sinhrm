<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Support;

use App\Modules\HiringRequests\Enums\HiringReason;
use App\Modules\HiringRequests\Exceptions\HiringException;
use Closure;

/**
 * Validated columns of a request from the form: only the known keys that were sent (partial update), the replaced
 * employee cleared unless the reason is a replacement, salary range checked, the configurable extra fields cleaned.
 */
final class RequestAttributes
{
    private const array KEYS = ['title', 'branch_id', 'department_id', 'position_id', 'headcount', 'reason', 'replaced_employee_id',
        'desired_start_date', 'salary_min', 'salary_max', 'currency', 'requirements', 'priority'];

    /**
     * @param  array<string, mixed>  $data  sent values merged with the stored ones the checks need
     * @param  array<string, mixed>|null  $given  keys actually sent (partial update); null = $data
     * @param  Closure(): list<array{key: string, label: string, type: string, required: bool, options?: list<string>}>  $fields  the form, read only when extra is written
     * @return array<string, mixed>
     *
     * @throws HiringException
     */
    public static function from(array $data, bool $creating, ?array $given, Closure $fields): array
    {
        $given ??= $data;
        $out = [];
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $given)) {
                $out[$key] = $given[$key];
            }
        }
        $rawReason = $data['reason'] ?? null;
        $reason = $rawReason instanceof HiringReason ? $rawReason : HiringReason::tryFrom(is_string($rawReason) ? $rawReason : '');
        if ($reason !== HiringReason::Replacement && array_key_exists('reason', $given)) {
            $out['replaced_employee_id'] = null;
        }
        $min = $data['salary_min'] ?? null;
        $max = $data['salary_max'] ?? null;
        if ($min !== null && $max !== null && (float) $min > (float) $max) {
            throw HiringException::salaryRange();
        }
        if (array_key_exists('extra', $given) || $creating) {
            $extra = is_array($given['extra'] ?? null) ? $given['extra'] : [];
            /** @var array<string, mixed> $extra */
            $out['extra'] = FormFields::clean($fields(), $extra);
        }

        return $out;
    }
}
