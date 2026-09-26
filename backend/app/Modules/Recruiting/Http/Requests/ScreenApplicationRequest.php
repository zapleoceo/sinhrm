<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Modules\Recruiting\Models\Application;
use Illuminate\Foundation\Http\FormRequest;

/** POST /applications/{application}/screening — no body; allowed to whoever may edit the candidate (CandidatePolicy::update). */
final class ScreenApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $application = $this->route('application');

        return $application instanceof Application && (bool) $this->user()?->can('update', $application->candidate);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
