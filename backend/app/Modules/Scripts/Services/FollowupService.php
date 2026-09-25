<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Services;

use App\Modules\Scripts\Contracts\ScriptRepository;
use App\Modules\Scripts\Contracts\TaskRepository;
use App\Modules\Scripts\Enums\FollowupCondition;
use App\Modules\Scripts\Enums\TaskType;
use App\Modules\Scripts\Support\FollowupRules;
use Illuminate\Support\Carbon;

/**
 * Turns the follow-up rules of every active script version into recruiter tasks. Each rule fires at most once per
 * application (tasks.unique(application_id, rule_key)), so running it every 30 minutes — or twice — is safe.
 * The task goes to the recruiter of the vacancy; due_at = when the condition became true (may already be overdue).
 */
final readonly class FollowupService
{
    public function __construct(private ScriptRepository $scripts, private TaskRepository $tasks) {}

    /** @return array{rules: int, applications: int, created: int} */
    public function run(Carbon $now): array
    {
        $rules = [];
        foreach ($this->scripts->active() as $script) {
            $content = $script->activeVersion?->content();
            foreach ($content->followups ?? [] as $followup) {
                $template = $followup['template_key'] === null ? null : $content?->template($followup['template_key']);
                $rules[] = [
                    'rule_key' => $script->id.':'.$followup['id'],
                    'condition' => FollowupCondition::from($followup['condition']),
                    'delay' => $followup['delay_days'],
                    'template_key' => $followup['template_key'],
                    'title' => mb_substr($template['title'] ?? $script->name, 0, 255),
                ];
            }
        }
        if ($rules === []) {
            return ['rules' => 0, 'applications' => 0, 'created' => 0];
        }

        $activities = $this->tasks->activities();
        $created = 0;
        foreach ($activities as $activity) {
            foreach ($rules as $rule) {
                $due = FollowupRules::dueAt($rule['condition'], $rule['delay'], $activity, $now);
                if ($due === null) {
                    continue;
                }
                $created += (int) $this->tasks->createFollowupOnce([
                    'assignee_id' => $activity->recruiterId,
                    'candidate_id' => $activity->candidateId,
                    'application_id' => $activity->applicationId,
                    'type' => TaskType::Followup->value,
                    'title' => $rule['title'],
                    'due_at' => $due,
                    'template_key' => $rule['template_key'],
                    'rule_key' => $rule['rule_key'],
                ]);
            }
        }

        return ['rules' => count($rules), 'applications' => count($activities), 'created' => $created];
    }
}
