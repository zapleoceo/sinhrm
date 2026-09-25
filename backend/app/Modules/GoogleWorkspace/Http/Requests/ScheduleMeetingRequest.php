<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Http\Requests;

use App\Modules\GoogleWorkspace\DTO\MeetingData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * POST /api/google/candidates/{candidate}/meetings. Whoever may edit the candidate (recruiter in scope, admin,
 * superadmin) may schedule; viewers and other branches → 403 (CandidatePolicy::update).
 */
final class ScheduleMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('candidate'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'start' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:480'],
            'type' => ['required', Rule::in(MeetingData::TYPES)],
            'invite_candidate' => ['sometimes', 'boolean'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function meeting(): MeetingData
    {
        return new MeetingData(
            title: trim($this->string('title')->toString()),
            start: Carbon::parse($this->string('start')->toString()),
            durationMinutes: $this->integer('duration_minutes'),
            type: $this->string('type')->toString(),
            inviteCandidate: $this->boolean('invite_candidate'),
            location: $this->filled('location') ? trim($this->string('location')->toString()) : null,
            notes: $this->filled('notes') ? trim($this->string('notes')->toString()) : null,
        );
    }
}
