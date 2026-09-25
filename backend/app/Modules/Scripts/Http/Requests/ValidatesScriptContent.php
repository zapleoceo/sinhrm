<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Http\Requests;

use App\Modules\Scripts\DTO\ScriptContent;
use App\Modules\Scripts\Enums\FollowupCondition;
use App\Modules\Scripts\Services\RulesScriptEvaluator;
use App\Modules\Scripts\Support\TemplateRenderer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shape and invariants of script content (steps, objections, templates, follow-ups, next-step patterns), shared by
 * "create script" and "save draft". Cross-field checks: unique ids/keys, known template variables, follow-ups refer
 * to existing templates, patterns are valid regular expressions.
 *
 * @mixin FormRequest
 */
trait ValidatesScriptContent
{
    /** @return array<string, mixed> */
    protected function contentRules(): array
    {
        return [
            'steps' => ['array', 'max:30'],
            'steps.*' => ['array'],
            'steps.*.id' => ['required', 'string', 'max:40', 'distinct'],
            'steps.*.title' => ['required', 'string', 'max:200'],
            'steps.*.goal' => ['nullable', 'string', 'max:1000'],
            'steps.*.sample' => ['nullable', 'string', 'max:5000'],
            'steps.*.required' => ['boolean'],
            'steps.*.weight' => ['required', 'integer', 'between:0,100'],
            'steps.*.keywords' => ['array', 'max:30'],
            'steps.*.keywords.*' => ['string', 'max:100'],
            'objections' => ['array', 'max:30'],
            'objections.*' => ['array'],
            'objections.*.id' => ['required', 'string', 'max:40', 'distinct'],
            'objections.*.trigger' => ['required', 'string', 'max:300'],
            'objections.*.answer' => ['required', 'string', 'max:5000'],
            'templates' => ['array', 'max:30'],
            'templates.*' => ['array'],
            'templates.*.id' => ['required', 'string', 'max:40', 'distinct'],
            'templates.*.key' => ['required', 'string', 'regex:/^[a-z0-9_-]{1,64}$/', 'distinct'],
            'templates.*.title' => ['required', 'string', 'max:200'],
            'templates.*.text' => ['required', 'string', 'max:5000'],
            'followups' => ['array', 'max:10'],
            'followups.*' => ['array'],
            'followups.*.id' => ['required', 'string', 'max:40', 'distinct'],
            'followups.*.condition' => ['required', Rule::in(FollowupCondition::values())],
            'followups.*.delay_days' => ['required', 'integer', 'between:0,60'],
            'followups.*.template_key' => ['nullable', 'string', 'max:64'],
            'next_step_patterns' => ['array'],
            'next_step_patterns.positive' => ['array', 'max:30'],
            'next_step_patterns.positive.*' => ['string', 'max:100'],
            'next_step_patterns.negative' => ['array', 'max:30'],
            'next_step_patterns.negative.*' => ['string', 'max:100'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $keys = [];
            foreach ((array) $this->input('templates', []) as $i => $template) {
                $keys[] = (string) ($template['key'] ?? '');
                $unknown = TemplateRenderer::unknownTokens((string) ($template['text'] ?? ''));
                if ($unknown !== []) {
                    $validator->errors()->add("templates.$i.text", 'unknown_variable: '.implode(', ', $unknown));
                }
            }
            foreach ((array) $this->input('followups', []) as $i => $followup) {
                $key = $followup['template_key'] ?? null;
                if (is_string($key) && $key !== '' && ! in_array($key, $keys, true)) {
                    $validator->errors()->add("followups.$i.template_key", 'unknown_template');
                }
            }
            foreach (['positive', 'negative'] as $kind) {
                foreach ((array) $this->input("next_step_patterns.$kind", []) as $i => $pattern) {
                    if (! RulesScriptEvaluator::isValidPattern((string) $pattern)) {
                        $validator->errors()->add("next_step_patterns.$kind.$i", 'invalid_pattern');
                    }
                }
            }
        }];
    }

    public function scriptContent(): ScriptContent
    {
        /** @var array<string, mixed> $data */
        $data = $this->safe()->only(['steps', 'objections', 'templates', 'followups', 'next_step_patterns']);

        return ScriptContent::fromArray($data);
    }
}
