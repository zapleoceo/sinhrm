<?php

declare(strict_types=1);

namespace App\Modules\Channels\Http\Requests;

use App\Modules\Recruiting\Models\Candidate;
use Illuminate\Foundation\Http\FormRequest;

/** POST /channels/{key}/simulate — optional sender (existing candidate or a contact) and text. */
final class SimulateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'candidate_id' => ['nullable', 'integer', 'exists:candidates,id'],
            'contact' => ['nullable', 'string', 'max:191'],
            'text' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function candidate(): ?Candidate
    {
        return $this->filled('candidate_id') ? Candidate::query()->find($this->integer('candidate_id')) : null;
    }

    public function contact(): ?string
    {
        return $this->filled('contact') ? $this->string('contact')->trim()->toString() : null;
    }

    public function text(): ?string
    {
        return $this->filled('text') ? $this->string('text')->trim()->toString() : null;
    }
}
