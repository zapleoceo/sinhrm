<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Models;

use App\Models\User;
use App\Modules\Directory\Models\Branch;
use App\Modules\Directory\Models\City;
use App\Modules\Directory\Models\Department;
use App\Modules\Directory\Models\Position;
use App\Modules\Directory\Models\VacancyCategory;
use App\Modules\Recruiting\Database\Factories\VacancyFactory;
use App\Modules\Recruiting\Enums\VacancyStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * @property int|null $hiring_manager_id
 * @property int $pipeline_id
 * @property VacancyStatus $status
 * @property string|null $description
 * @property bool $published
 * @property string|null $slug
 * @property string|null $public_description
 * @property int|null $category_id
 * @property int|null $city_id
 * @property string|null $country
 * @property string|null $employment_type
 * @property string|null $work_format
 * @property string|null $experience_level
 * @property string|null $education_level
 * @property string|null $salary_min
 * @property string|null $salary_max
 * @property string $salary_currency
 * @property bool $salary_visible
 * @property list<array{lang: string, level: string}>|null $languages
 * @property string|null $requirements
 * @property string|null $responsibilities
 * @property string|null $additional_info
 * @property list<array{site: string, url: string|null, date: string|null}>|null $external_postings
 * @property-read bool $is_active
 * @property-read VacancyCategory|null $category
 * @property-read City|null $city
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
 * @property-read User|null $hiringManager
 * @property-read Pipeline $pipeline
 * @property-read Collection<int, Application> $applications
 */
final class Vacancy extends Model
{
    /** @use HasFactory<VacancyFactory> */
    use HasFactory;

    protected $fillable = [
        'title', 'branch_id', 'department_id', 'position_id', 'recruiter_id', 'hiring_manager_id', 'pipeline_id',
        'status', 'description', 'opened_at', 'closed_at', 'published', 'slug', 'public_description',
        'category_id', 'city_id', 'country', 'employment_type', 'work_format', 'experience_level', 'education_level',
        'salary_min', 'salary_max', 'salary_currency', 'salary_visible', 'languages', 'requirements',
        'responsibilities', 'additional_info', 'external_postings',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'open', 'published' => false, 'salary_currency' => 'UAH', 'salary_visible' => false];

    /**
     * The single definition of an active vacancy: open AND published on the career page (/jobs). Used by the list
     * (filter + count), the public career API and reports.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', VacancyStatus::Open->value)->where('published', true);
    }

    /** @return Attribute<bool, never> */
    protected function isActive(): Attribute
    {
        return Attribute::get(fn (): bool => $this->status === VacancyStatus::Open && $this->published);
    }

    /** @return BelongsTo<VacancyCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(VacancyCategory::class);
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

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

    /**
     * Contextual role: sees and works this vacancy's candidates regardless of the global role.
     *
     * @return BelongsTo<User, $this>
     */
    public function hiringManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hiring_manager_id');
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
        return [
            'status' => VacancyStatus::class, 'published' => 'boolean', 'opened_at' => 'datetime', 'closed_at' => 'datetime',
            'salary_min' => 'decimal:2', 'salary_max' => 'decimal:2', 'salary_visible' => 'boolean',
            'languages' => 'array', 'external_postings' => 'array',
        ];
    }

    protected static function newFactory(): VacancyFactory
    {
        return VacancyFactory::new();
    }
}
