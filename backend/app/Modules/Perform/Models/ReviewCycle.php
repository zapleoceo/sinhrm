<?php

declare(strict_types=1);

namespace App\Modules\Perform\Models;

use App\Modules\Perform\Enums\CycleStatus;
use App\Modules\Perform\Enums\ReviewType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A review cycle (performance review / 360).
 *
 * @property int $id
 * @property string $name
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property array{branch_ids?: list<int>, department_ids?: list<int>} $participants
 * @property list<string> $types
 * @property list<int> $competency_ids
 * @property bool $anonymous
 * @property array<string, string> $deadlines
 * @property CycleStatus $status
 * @property int|null $created_by
 * @property Carbon|null $activated_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $assignments_count
 * @property int|null $submitted_count
 */
final class ReviewCycle extends Model
{
    protected $fillable = [
        'name', 'period_start', 'period_end', 'participants', 'types', 'competency_ids', 'anonymous', 'deadlines',
        'status', 'created_by', 'activated_at', 'closed_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft', 'anonymous' => true];

    /** @return HasMany<ReviewAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(ReviewAssignment::class, 'cycle_id');
    }

    /** @return list<ReviewType> */
    public function reviewTypes(): array
    {
        $types = [];
        foreach ($this->types as $value) {
            $type = ReviewType::tryFrom($value);
            if ($type !== null) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'participants' => 'array',
            'types' => 'array',
            'competency_ids' => 'array',
            'deadlines' => 'array',
            'anonymous' => 'boolean',
            'status' => CycleStatus::class,
            'activated_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
