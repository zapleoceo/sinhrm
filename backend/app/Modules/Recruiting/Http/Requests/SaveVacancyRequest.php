<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Models\User;
use App\Modules\Directory\Enums\DirectoryStatus;
use App\Modules\Directory\Models\Branch;
use App\Modules\Directory\Models\Department;
use App\Modules\Directory\Models\Position;
use App\Modules\Recruiting\DTO\VacancyData;
use App\Modules\Recruiting\Enums\VacancyStatus;
use App\Modules\Recruiting\Models\Pipeline;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Providers\RecruitingServiceProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /vacancies (title + branch required) and PATCH /vacancies/{vacancy} (partial). */
final class SaveVacancyRequest extends FormRequest
{
    private const array FIELDS = ['title', 'branch_id', 'department_id', 'position_id', 'recruiter_id', 'hiring_manager_id', 'pipeline_id', 'status', 'description'];

    public function authorize(): bool
    {
        $vacancy = $this->route('vacancy');

        return $vacancy instanceof Vacancy
            ? (bool) $this->user()?->can('update', $vacancy)
            : (bool) $this->user()?->can('create', Vacancy::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $active = static fn (string $model) => Rule::exists($model, 'id')->where('status', DirectoryStatus::Active->value);

        return [
            'title' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'branch_id' => [$creating ? 'required' : 'sometimes', 'required', 'integer', $active(Branch::class)],
            'department_id' => ['sometimes', 'nullable', 'integer', $active(Department::class)],
            'position_id' => ['sometimes', 'nullable', 'integer', $active(Position::class)],
            'recruiter_id' => ['sometimes', 'required', 'integer', Rule::exists(User::class, 'id')->where('status', 'active')],
            // Contextual role; only recruiting writers assign it (a hiring manager cannot hand the vacancy over).
            'hiring_manager_id' => $this->canAssignHiringManager()
                ? ['sometimes', 'nullable', 'integer', Rule::exists(User::class, 'id')->where('status', 'active')]
                : ['prohibited'],
            // The pipeline is fixed once the vacancy exists: stages of applications must stay valid.
            'pipeline_id' => $creating ? ['sometimes', 'required', 'integer', Rule::exists(Pipeline::class, 'id')] : ['prohibited'],
            'status' => ['sometimes', 'required', Rule::enum(VacancyStatus::class)],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
    }

    private function canAssignHiringManager(): bool
    {
        return (bool) $this->user()?->can(RecruitingServiceProvider::WRITE);
    }

    public function vacancyData(): VacancyData
    {
        $attributes = [];
        foreach (self::FIELDS as $field) {
            if ($this->has($field)) {
                $attributes[$field] = $this->input($field);
            }
        }
        if (isset($attributes['title']) && is_string($attributes['title'])) {
            $attributes['title'] = trim($attributes['title']);
        }
        foreach (['branch_id', 'department_id', 'position_id', 'recruiter_id', 'hiring_manager_id', 'pipeline_id'] as $id) {
            if (isset($attributes[$id]) && is_numeric($attributes[$id])) {
                $attributes[$id] = (int) $attributes[$id];
            }
        }

        return new VacancyData($attributes);
    }
}
