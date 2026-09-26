<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Http\Requests;

use App\Modules\Pulse\Models\SurveyWave;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Results / comparison filters: ?segment=branch|department&with=<wave id>. */
final class ReportRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'segment' => ['nullable', Rule::in(['branch', 'department'])],
            'with' => ['nullable', 'integer', Rule::exists(SurveyWave::class, 'id')],
        ];
    }

    public function breakdown(): ?string
    {
        return $this->filled('segment') ? (string) $this->string('segment') : null;
    }

    public function withWave(): ?int
    {
        return $this->filled('with') ? $this->integer('with') : null;
    }
}
