<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Modules\Directory\Enums\DirectoryStatus;
use App\Modules\Directory\Models\Department;
use App\Modules\Directory\Models\Position;
use App\Modules\Recruiting\Models\Vacancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /vacancy-templates: a name and the form values (validated with the vacancy rules; unknown keys dropped). */
final class SaveVacancyTemplateRequest extends FormRequest
{
    /** Form keys a template keeps: the content of a vacancy, not its branch, people, status or publication. */
    public const array KEYS = ['title', 'department_id', 'position_id', 'description', 'public_description', ...SaveVacancyRequest::FORM_FIELDS];

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Vacancy::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $active = static fn (string $model) => Rule::exists($model, 'id')->where('status', DirectoryStatus::Active->value);
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'data' => ['required', 'array'],
            'data.title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'data.department_id' => ['sometimes', 'nullable', 'integer', $active(Department::class)],
            'data.position_id' => ['sometimes', 'nullable', 'integer', $active(Position::class)],
            'data.description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'data.public_description' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
        foreach (SaveVacancyRequest::formRules() as $key => $rule) {
            $rules['data.'.$key] = $rule;
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    public function templateData(): array
    {
        /** @var array<string, mixed> $data */
        $data = (array) $this->validated('data');

        return array_intersect_key($data, array_flip(self::KEYS));
    }
}
