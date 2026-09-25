<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Modules\Recruiting\DTO\VacancyFilter;
use App\Modules\Recruiting\Enums\VacancyStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListVacanciesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(VacancyStatus::class)],
            // Query strings arrive as strings ("20"): 'integer' accepts numeric strings.
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'recruiter_id' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'between:1,200'],
        ];
    }

    public function filter(): VacancyFilter
    {
        return new VacancyFilter(
            q: $this->filled('q') ? $this->string('q')->trim()->toString() : null,
            status: $this->enum('status', VacancyStatus::class),
            branchId: $this->filled('branch_id') ? $this->integer('branch_id') : null,
            recruiterId: $this->filled('recruiter_id') ? $this->integer('recruiter_id') : null,
            perPage: $this->integer('perPage', 50),
        );
    }
}
