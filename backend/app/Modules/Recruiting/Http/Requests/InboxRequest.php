<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Recruiting\Models\Vacancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Inbox actions on /inbox/{touchpoint}/…:
 *  - link:              {candidate_id} — the candidate must be visible to the user;
 *  - create-candidate:  {full_name, vacancy_id?} — the vacancy must be editable by the user.
 */
final class InboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $touchpoint = $this->route('touchpoint');
        if ($user === null || ! $touchpoint instanceof Touchpoint || ! $user->can('resolve', $touchpoint)) {
            return false;
        }
        $candidate = $this->linkTarget();
        if ($candidate !== null && ! $user->can('view', $candidate)) {
            return false;
        }
        $vacancy = is_numeric($this->input('vacancy_id')) ? Vacancy::query()->find((int) $this->input('vacancy_id')) : null;

        return $vacancy === null || $user->can('update', $vacancy);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->isLink()) {
            return ['candidate_id' => ['required', 'integer', Rule::exists(Candidate::class, 'id')]];
        }

        return [
            'full_name' => ['required', 'string', 'min:2', 'max:255'],
            'vacancy_id' => ['nullable', 'integer', Rule::exists(Vacancy::class, 'id')],
        ];
    }

    public function linkTarget(): ?Candidate
    {
        return $this->isLink() && is_numeric($this->input('candidate_id'))
            ? Candidate::query()->find((int) $this->input('candidate_id'))
            : null;
    }

    public function fullName(): string
    {
        return $this->string('full_name')->trim()->toString();
    }

    public function vacancyId(): ?int
    {
        return $this->filled('vacancy_id') ? $this->integer('vacancy_id') : null;
    }

    private function isLink(): bool
    {
        return $this->route()?->getName() === 'recruiting.inbox.link';
    }
}
