<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use App\Modules\People\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One period of an asset with an employee (returned_at null = still with them).
 *
 * @property int $id
 * @property int $asset_id
 * @property int $employee_id
 * @property Carbon $assigned_at
 * @property Carbon|null $returned_at
 * @property string|null $condition_out
 * @property string|null $condition_in
 * @property int|null $assigned_by
 * @property int|null $returned_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Asset $asset
 * @property-read Employee $employee
 */
final class AssetAssignment extends Model
{
    protected $table = 'asset_assignments';

    protected $fillable = ['asset_id', 'employee_id', 'assigned_at', 'returned_at', 'condition_out', 'condition_in', 'assigned_by', 'returned_by'];

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['assigned_at' => 'date', 'returned_at' => 'date'];
    }
}
