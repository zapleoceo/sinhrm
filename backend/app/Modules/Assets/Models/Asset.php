<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\People\Models\Employee;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $inventory_number
 * @property string|null $serial
 * @property string $name
 * @property int|null $type_id
 * @property AssetStatus $status
 * @property string|null $cost
 * @property Carbon|null $purchased_at
 * @property string|null $notes
 * @property int|null $employee_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AssetType|null $type
 * @property-read Employee|null $employee
 * @property-read Collection<int, AssetAssignment> $assignments
 */
final class Asset extends Model
{
    protected $table = 'assets';

    protected $fillable = ['inventory_number', 'serial', 'name', 'type_id', 'status', 'cost', 'purchased_at', 'notes', 'employee_id'];

    /** @return BelongsTo<AssetType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(AssetType::class, 'type_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<AssetAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class)->orderByDesc('assigned_at')->orderByDesc('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => AssetStatus::class, 'cost' => 'decimal:2', 'purchased_at' => 'date'];
    }
}
