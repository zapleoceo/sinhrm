<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Http\Requests;

use App\Models\User;
use App\Modules\Workflows\Enums\AssigneeRule;
use App\Modules\Workflows\Enums\StepAction;
use App\Modules\Workflows\Enums\WorkflowKind;
use App\Modules\Workflows\Enums\WorkflowTrigger;
use App\Modules\Workflows\Support\ExecutorRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/workflows/templates and PUT /api/workflows/templates/{id}: the template with ALL its steps (the editor
 * saves the whole list; steps with an id are updated, new ones created, missing ones deleted). Each step's config is
 * validated by its action's executor (StepExecutor::configRules); unknown config keys are dropped.
 */
final class SaveWorkflowTemplateRequest extends FormRequest
{
    public const int MAX_STEPS = 50;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(WorkflowKind::class)],
            'trigger' => ['required', Rule::enum(WorkflowTrigger::class)],
            'active' => ['sometimes', 'boolean'],
            'probation_days' => ['sometimes', 'integer', 'between:1,730'],
            'steps' => ['present', 'array', 'max:'.self::MAX_STEPS],
            'steps.*' => ['array'],
            'steps.*.id' => ['nullable', 'integer'],
            'steps.*.title' => ['required', 'string', 'max:255'],
            'steps.*.action' => ['required', Rule::enum(StepAction::class)],
            'steps.*.offset_days' => ['required', 'integer', 'between:-365,365'],
            'steps.*.assignee_rule' => ['required', Rule::enum(AssigneeRule::class)],
            'steps.*.assignee_user_id' => ['nullable', 'integer', 'required_if:steps.*.assignee_rule,specific_user', Rule::exists(User::class, 'id')],
            'steps.*.config' => ['nullable', 'array'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $registry = app(ExecutorRegistry::class);
            $steps = $this->input('steps');
            foreach (is_array($steps) ? array_values($steps) : [] as $i => $step) {
                $action = is_array($step) ? StepAction::tryFrom((string) ($step['action'] ?? '')) : null;
                if ($action === null) {
                    continue;
                }
                $config = is_array($step['config'] ?? null) ? $step['config'] : [];
                $check = ValidatorFacade::make($config, $registry->for($action)->configRules());
                foreach ($check->errors()->messages() as $key => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add("steps.$i.config.$key", $message);
                    }
                }
            }
        }];
    }

    /** @return array<string, mixed> */
    public function templateAttributes(): array
    {
        $data = $this->safe()->only(['name', 'kind', 'trigger', 'probation_days']);
        if ($this->has('active')) {
            $data['active'] = $this->boolean('active');
        }

        return $data;
    }

    /** @return list<array<string, mixed>> steps in order, config reduced to the keys its action knows */
    public function steps(): array
    {
        $registry = app(ExecutorRegistry::class);
        $steps = [];
        foreach ((array) $this->validated('steps', []) as $step) {
            $action = StepAction::from((string) $step['action']);
            $rule = AssigneeRule::from((string) $step['assignee_rule']);
            $config = is_array($step['config'] ?? null) ? $step['config'] : [];
            $steps[] = [
                'id' => $step['id'] ?? null,
                'title' => $step['title'],
                'action' => $action->value,
                'offset_days' => (int) $step['offset_days'],
                'assignee_rule' => $rule->value,
                'assignee_user_id' => $rule === AssigneeRule::SpecificUser ? (int) $step['assignee_user_id'] : null,
                'config' => array_intersect_key($config, $registry->for($action)->configRules()),
            ];
        }

        return $steps;
    }
}
