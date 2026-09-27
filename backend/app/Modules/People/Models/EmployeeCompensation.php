<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One compensation record of an employee. The current one is the latest effective_on that is not in the future.
 * Amounts are masked in the audit log (not on the allow-list).
 *
 * @property int $id
 * @property int $employee_id
 * @property string $amount
 * @property string $currency UAH|USD|EUR
 * @property string $period month|hour
 * @property Carbon $effective_on
 * @property string|null $reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
final class EmployeeCompensation extends Model
{
    public const array CURRENCIES = ['UAH', 'USD', 'EUR'];

    public const array PERIODS = ['month', 'hour'];

    protected $fillable = ['employee_id', 'amount', 'currency', 'period', 'effective_on', 'reason', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_on' => 'date', 'amount' => 'decimal:2'];
    }
}
