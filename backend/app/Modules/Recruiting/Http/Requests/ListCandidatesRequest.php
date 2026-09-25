<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Modules\Recruiting\DTO\CandidateFilter;
use App\Modules\Recruiting\Enums\ApplicationStatus;
use App\Modules\Recruiting\Enums\CandidateSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListCandidatesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'vacancy_id' => ['nullable', 'integer', 'min:1'],
            'stage_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(ApplicationStatus::class)],
            'source' => ['nullable', Rule::enum(CandidateSource::class)],
            'owner_id' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'between:1,200'],
        ];
    }

    public function filter(): CandidateFilter
    {
        $int = fn (string $key): ?int => $this->filled($key) ? $this->integer($key) : null;

        return new CandidateFilter(
            q: $this->filled('q') ? $this->string('q')->trim()->toString() : null,
            vacancyId: $int('vacancy_id'),
            stageId: $int('stage_id'),
            status: $this->enum('status', ApplicationStatus::class),
            source: $this->enum('source', CandidateSource::class),
            ownerId: $int('owner_id'),
            perPage: $this->integer('perPage', 50),
        );
    }
}
