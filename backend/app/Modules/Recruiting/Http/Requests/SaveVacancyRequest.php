<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Models\User;
use App\Modules\Directory\Enums\DirectoryStatus;
use App\Modules\Directory\Models\Branch;
use App\Modules\Directory\Models\City;
use App\Modules\Directory\Models\Department;
use App\Modules\Directory\Models\Position;
use App\Modules\Directory\Models\VacancyCategory;
use App\Modules\Recruiting\DTO\VacancyData;
use App\Modules\Recruiting\Enums\VacancyStatus;
use App\Modules\Recruiting\Models\Pipeline;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Providers\RecruitingServiceProvider;
use App\Modules\Recruiting\Support\VacancyOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** POST /vacancies (title + branch required) and PATCH /vacancies/{vacancy} (partial). */
final class SaveVacancyRequest extends FormRequest
{
    private const array FIELDS = [
        'title', 'branch_id', 'department_id', 'position_id', 'recruiter_id', 'hiring_manager_id', 'pipeline_id', 'status',
        'description', 'published', 'public_description', ...self::FORM_FIELDS,
    ];

    /** Fields of the full vacancy form (also the keys a vacancy template may keep). */
    public const array FORM_FIELDS = [
        'category_id', 'city_id', 'country', 'employment_type', 'work_format', 'experience_level', 'education_level',
        'salary_min', 'salary_max', 'salary_currency', 'salary_visible', 'languages', 'requirements', 'responsibilities',
        'additional_info', 'external_postings',
    ];

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
            // Career page (/jobs): published + the text candidates see (the internal description stays internal).
            'published' => ['sometimes', 'boolean'],
            'public_description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            ...self::formRules(),
        ];
    }

    /**
     * Rules of the full-form fields (shared with vacancy templates). Sections are Markdown: stored as typed, rendered
     * on the server with raw HTML escaped (Documents MarkdownRenderer), never sent to a browser as raw HTML.
     *
     * @return array<string, mixed>
     */
    public static function formRules(): array
    {
        $active = static fn (string $model) => Rule::exists($model, 'id')->where('status', DirectoryStatus::Active->value);
        $in = static fn (array $values) => Rule::in($values);

        return [
            'category_id' => ['sometimes', 'nullable', 'integer', $active(VacancyCategory::class)],
            'city_id' => ['sometimes', 'nullable', 'integer', $active(City::class)],
            'country' => ['sometimes', 'nullable', 'string', $in(VacancyOptions::COUNTRIES)],
            'employment_type' => ['sometimes', 'nullable', 'string', $in(VacancyOptions::EMPLOYMENT_TYPES)],
            'work_format' => ['sometimes', 'nullable', 'string', $in(VacancyOptions::WORK_FORMATS)],
            'experience_level' => ['sometimes', 'nullable', 'string', $in(VacancyOptions::EXPERIENCE_LEVELS)],
            'education_level' => ['sometimes', 'nullable', 'string', $in(VacancyOptions::EDUCATION_LEVELS)],
            'salary_min' => ['sometimes', 'nullable', 'numeric', 'between:0,99999999'],
            'salary_max' => ['sometimes', 'nullable', 'numeric', 'between:0,99999999'],
            'salary_currency' => ['sometimes', 'required', 'string', $in(VacancyOptions::CURRENCIES)],
            'salary_visible' => ['sometimes', 'boolean'],
            'languages' => ['sometimes', 'nullable', 'array', 'max:10'],
            'languages.*' => ['array:lang,level'],
            'languages.*.lang' => ['required', 'string', 'distinct', $in(VacancyOptions::LANGUAGES)],
            'languages.*.level' => ['required', 'string', $in(VacancyOptions::LANGUAGE_LEVELS)],
            'requirements' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'responsibilities' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'additional_info' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'external_postings' => ['sometimes', 'nullable', 'array', 'max:20'],
            'external_postings.*' => ['array:site,url,date'],
            'external_postings.*.site' => ['required', 'string', $in(VacancyOptions::EXTERNAL_SITES)],
            'external_postings.*.url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'external_postings.*.date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [static function (Validator $validator): void {
            $data = $validator->getData();
            $min = $data['salary_min'] ?? null;
            $max = $data['salary_max'] ?? null;
            if (is_numeric($min) && is_numeric($max) && (float) $max < (float) $min) {
                $validator->errors()->add('salary_max', 'salary_range');
            }
        }];
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
        foreach (['branch_id', 'department_id', 'position_id', 'recruiter_id', 'hiring_manager_id', 'pipeline_id', 'category_id', 'city_id'] as $id) {
            if (isset($attributes[$id]) && is_numeric($attributes[$id])) {
                $attributes[$id] = (int) $attributes[$id];
            }
        }

        foreach (['published', 'salary_visible'] as $flag) {
            if (isset($attributes[$flag])) {
                $attributes[$flag] = $this->boolean($flag);
            }
        }
        foreach (['languages', 'external_postings'] as $list) {
            if (array_key_exists($list, $attributes)) {
                $attributes[$list] = is_array($attributes[$list]) && $attributes[$list] !== [] ? array_values($attributes[$list]) : null;
            }
        }

        return new VacancyData($attributes);
    }
}
