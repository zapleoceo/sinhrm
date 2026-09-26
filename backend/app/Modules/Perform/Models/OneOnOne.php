<?php

declare(strict_types=1);

namespace App\Modules\Perform\Models;

use App\Modules\People\Models\Employee;
use App\Modules\Perform\Enums\OneOnOneStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A 1:1 meeting of a manager with an employee. notes_private_manager is for the meeting's manager only.
 *
 * @property int $id
 * @property int $manager_employee_id
 * @property int $employee_id
 * @property Carbon $scheduled_at
 * @property int|null $template_id
 * @property list<array{id: string, text: string, done: bool}> $agenda
 * @property string|null $notes_private_manager
 * @property string|null $notes_shared
 * @property list<array{id: string, text: string, done: bool, due_on?: string|null}> $action_items
 * @property OneOnOneStatus $status
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee $manager
 * @property-read Employee $employee
 */
final class OneOnOne extends Model
{
    protected $table = 'one_on_ones';

    protected $fillable = [
        'manager_employee_id', 'employee_id', 'scheduled_at', 'template_id', 'agenda', 'notes_private_manager',
        'notes_shared', 'action_items', 'status', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'scheduled'];

    /** @return BelongsTo<Employee, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_employee_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'agenda' => 'array',
            'action_items' => 'array',
            'status' => OneOnOneStatus::class,
        ];
    }
}
