<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Modules\Recruiting\Ai\VacancyTextPrompt;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Support\VacancyOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /vacancy-text: facts of the vacancy for an AI draft of one section (no personal data is accepted). */
final class VacancyTextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Vacancy::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'section' => ['required', 'string', Rule::in(VacancyTextPrompt::SECTIONS)],
            'title' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'employment_type' => ['nullable', 'string', Rule::in(VacancyOptions::EMPLOYMENT_TYPES)],
            'experience_level' => ['nullable', 'string', Rule::in(VacancyOptions::EXPERIENCE_LEVELS)],
        ];
    }

    /** @return array{section: string, title: string, category_id: int|null, branch_id: int|null, employment_type: string|null, experience_level: string|null} */
    public function facts(): array
    {
        return [
            'section' => $this->string('section')->toString(),
            'title' => $this->string('title')->trim()->toString(),
            'category_id' => $this->filled('category_id') ? $this->integer('category_id') : null,
            'branch_id' => $this->filled('branch_id') ? $this->integer('branch_id') : null,
            'employment_type' => $this->filled('employment_type') ? $this->string('employment_type')->toString() : null,
            'experience_level' => $this->filled('experience_level') ? $this->string('experience_level')->toString() : null,
        ];
    }
}
