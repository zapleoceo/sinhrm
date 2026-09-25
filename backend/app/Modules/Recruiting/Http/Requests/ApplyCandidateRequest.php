<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Vacancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /vacancies/{vacancy}/applications {candidate_id}: needs write access to the vacancy and sight of the candidate. */
final class ApplyCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $vacancy = $this->route('vacancy');
        $user = $this->user();
        if (! $vacancy instanceof Vacancy || $user === null || ! $user->can('update', $vacancy)) {
            return false;
        }
        $candidate = $this->candidate();

        return $candidate === null || $user->can('view', $candidate);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['candidate_id' => ['required', 'integer', Rule::exists(Candidate::class, 'id')]];
    }

    public function candidate(): ?Candidate
    {
        return is_numeric($this->input('candidate_id')) ? Candidate::query()->find((int) $this->input('candidate_id')) : null;
    }
}
