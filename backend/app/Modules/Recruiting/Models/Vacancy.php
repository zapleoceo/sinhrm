<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Models;

use App\Models\User;
use App\Modules\Directory\Models\Branch;
use App\Modules\Directory\Models\Department;
use App\Modules\Directory\Models\Position;
use App\Modules\Recruiting\Database\Factories\VacancyFactory;
use App\Modules\Recruiting\Enums\VacancyStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title
 * @property int $branch_id
 * @property int|null $department_id
 * @property int|null $position_id
 * @property int $recruiter_id
 * @property int $pipeline_id
 * @property VacancyStatus $status
 * @property string|null $description
 * @property Carbon|null $opened_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $applications_count
 * @property int|null $active_applications_count
 * @property-read Branch $branch
 * @property-read Department|null $department
 * @property-read Position|null $position
 * @property-read User $recruiter
 * @property-read Pipeline $pipeline
 * @property-read Collection<int, Application> $applications
 */
final class Vacancy extends Model
{
    /** @use HasFactory<VacancyFactory> */
    use HasFactory;

    protected $fillable = [
        'title', 'branch_id', 'department_id', 'position_id', 'recruiter_id', 'pipeline_id',
        'status', 'description', 'opened_at', 'closed_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'open'];

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Position, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recruiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recruiter_id');
    }

    /** @return BelongsTo<Pipeline, $this> */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    /** @return HasMany<Application, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => VacancyStatus::class, 'opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    protected static function newFactory(): VacancyFactory
    {
        return VacancyFactory::new();
    }
}
