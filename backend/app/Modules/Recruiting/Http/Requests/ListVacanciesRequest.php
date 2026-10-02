<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Modules\Core\Http\Requests\Concerns\Paginates;
use App\Modules\Recruiting\DTO\VacancyFilter;
use App\Modules\Recruiting\Enums\VacancyStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListVacanciesRequest extends FormRequest
{
    use Paginates;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(VacancyStatus::class)],
            // Query strings arrive as strings ("20"): 'integer' accepts numeric strings.
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'recruiter_id' => ['nullable', 'integer', 'min:1'],
            'perPage' => $this->perPageRules(),
            // "1"/"0"/"true"/"false" from the query string.
            'active' => ['nullable', 'in:0,1,true,false'],
        ];
    }

    public function filter(): VacancyFilter
    {
        return new VacancyFilter(
            q: $this->filled('q') ? $this->string('q')->trim()->toString() : null,
            status: $this->enum('status', VacancyStatus::class),
            branchId: $this->filled('branch_id') ? $this->integer('branch_id') : null,
            recruiterId: $this->filled('recruiter_id') ? $this->integer('recruiter_id') : null,
            perPage: $this->perPageOr(),
            active: $this->boolean('active'),
        );
    }
}
