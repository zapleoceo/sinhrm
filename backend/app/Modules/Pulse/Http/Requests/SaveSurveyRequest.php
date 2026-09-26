<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Http\Requests;

use App\Modules\Pulse\Enums\LifecycleTrigger;
use App\Modules\Pulse\Enums\QuestionType;
use App\Modules\Pulse\Enums\SurveyType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST/PUT /api/pulse/surveys {title, type, description?, active?, lifecycle_trigger? (type=lifecycle only),
 * questions: [{id, type, text, options? (single/multi: 2..20), required?}] (1..50)}. Admins.
 */
final class SaveSurveyRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(SurveyType::class)],
            'description' => ['nullable', 'string', 'max:5000'],
            'active' => ['sometimes', 'boolean'],
            'lifecycle_trigger' => ['nullable', 'required_if:type,lifecycle', 'prohibited_unless:type,lifecycle', Rule::enum(LifecycleTrigger::class)],
            'questions' => ['required', 'array', 'min:1', 'max:50'],
            'questions.*.id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,32}$/', 'distinct'],
            'questions.*.type' => ['required', Rule::enum(QuestionType::class)],
            'questions.*.text' => ['required', 'string', 'max:500'],
            'questions.*.options' => ['array', 'min:2', 'max:20', 'required_if:questions.*.type,single,multi'],
            'questions.*.options.*' => ['required', 'string', 'max:200'],
            'questions.*.required' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $questions = array_values(array_map(static function (array $q): array {
            $out = ['id' => (string) $q['id'], 'type' => (string) $q['type'], 'text' => (string) $q['text'], 'required' => (bool) ($q['required'] ?? false)];
            if (in_array($q['type'], ['single', 'multi'], true)) {
                $out['options'] = array_values(array_map('strval', (array) ($q['options'] ?? [])));
            }

            return $out;
        }, (array) $this->input('questions')));

        return [
            'title' => (string) $this->string('title'),
            'type' => (string) $this->string('type'),
            'description' => $this->filled('description') ? (string) $this->string('description') : null,
            'active' => $this->boolean('active', true),
            'lifecycle_trigger' => $this->filled('lifecycle_trigger') ? (string) $this->string('lifecycle_trigger') : null,
            'questions' => $questions,
        ];
    }
}
