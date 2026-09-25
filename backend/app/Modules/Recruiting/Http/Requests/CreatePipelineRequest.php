<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Modules\Recruiting\Enums\StageKind;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /pipelines {name, stages: [{name, kind, is_terminal}]} in order. Needs at least one non-terminal stage first
 * (applications start there) and one terminal "closed" stage (rejection).
 */
final class CreatePipelineRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'stages' => ['required', 'array', 'min:2', 'max:20', function (string $attribute, mixed $value, Closure $fail): void {
                $stages = $this->stages();
                if ($stages === [] || $stages[0]['is_terminal']) {
                    $fail('The first stage must not be terminal.');
                }
                $hasReject = array_filter($stages, static fn (array $s): bool => $s['is_terminal'] && $s['kind'] === StageKind::Closed->value);
                if ($hasReject === []) {
                    $fail('A terminal "closed" stage is required.');
                }
            }],
            'stages.*.name' => ['required', 'string', 'min:1', 'max:255'],
            'stages.*.kind' => ['required', Rule::enum(StageKind::class)],
            'stages.*.is_terminal' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<array{name: string, kind: string, is_terminal: bool}> */
    public function stages(): array
    {
        $out = [];
        foreach ((array) $this->input('stages', []) as $stage) {
            if (! is_array($stage)) {
                continue;
            }
            $out[] = [
                'name' => trim(is_string($stage['name'] ?? null) ? $stage['name'] : ''),
                'kind' => is_string($stage['kind'] ?? null) ? $stage['kind'] : '',
                'is_terminal' => filter_var($stage['is_terminal'] ?? false, FILTER_VALIDATE_BOOL),
            ];
        }

        return $out;
    }
}
