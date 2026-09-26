<?php

declare(strict_types=1);

namespace App\Modules\Perform\Models;

use App\Modules\People\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A key performance indicator of one employee for one period: target and actual.
 *
 * @property int $id
 * @property int $employee_id
 * @property string $metric
 * @property string|null $unit
 * @property string $period
 * @property string $target
 * @property string|null $actual
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee $employee
 */
final class Kpi extends Model
{
    protected $fillable = ['employee_id', 'metric', 'unit', 'period', 'target', 'actual', 'created_by'];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
