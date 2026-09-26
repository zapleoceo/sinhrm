<?php

declare(strict_types=1);

namespace App\Modules\Perform\Models;

use App\Modules\People\Models\Employee;
use App\Modules\Perform\Enums\FeedbackType;
use App\Modules\Perform\Enums\FeedbackVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Continuous feedback between employees, or a request for it.
 *
 * @property int $id
 * @property int $from_employee_id
 * @property int $to_employee_id
 * @property FeedbackType $type
 * @property string $text
 * @property FeedbackVisibility $visibility
 * @property int|null $request_id
 * @property Carbon|null $answered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee $from
 * @property-read Employee $to
 */
final class Feedback extends Model
{
    protected $table = 'feedback';

    protected $fillable = ['from_employee_id', 'to_employee_id', 'type', 'text', 'visibility', 'request_id', 'answered_at'];

    /** @return BelongsTo<Employee, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_employee_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function to(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_employee_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => FeedbackType::class, 'visibility' => FeedbackVisibility::class, 'answered_at' => 'datetime'];
    }
}
