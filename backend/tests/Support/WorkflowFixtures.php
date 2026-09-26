<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Workflows\Models\WorkflowStep;
use App\Modules\Workflows\Models\WorkflowTemplate;

/** Builders for Workflows tests. Synthetic data only (public repository). */
trait WorkflowFixtures
{
    protected const string OPS_URL = '/api/ops/jobs/run';

    protected const string OPS_SECRET = 'test-secret';

    /**
     * A template with steps; each step: [action, offset_days, assignee_rule, config, title?].
     *
     * @param  list<array{0: string, 1?: int, 2?: string, 3?: array<string, mixed>, 4?: string}>  $steps
     * @param  array<string, mixed>  $attributes
     */
    protected function workflow(array $steps, array $attributes = []): WorkflowTemplate
    {
        $template = WorkflowTemplate::query()->create($attributes + [
            'name' => 'Onboarding sample',
            'kind' => 'onboarding',
            'trigger' => 'manual',
            'active' => true,
        ]);
        foreach ($steps as $position => $step) {
            WorkflowStep::query()->create([
                'template_id' => $template->id,
                'position' => $position,
                'title' => $step[4] ?? 'Step '.($position + 1),
                'action' => $step[0],
                'offset_days' => $step[1] ?? 0,
                'assignee_rule' => $step[2] ?? 'hr_admin',
                'config' => $step[3] ?? [],
            ]);
        }

        return $template->refresh();
    }

    /** @return array<string, mixed> the "workflows.tick" part of the ops answer */
    protected function tick(): array
    {
        config(['ops.secret' => self::OPS_SECRET]);
        $response = $this->postJson(self::OPS_URL, [], ['X-Ops-Secret' => self::OPS_SECRET])->assertOk();
        $jobs = $response->json('jobs');
        $this->assertIsArray($jobs);
        $tick = $jobs['workflows.tick'] ?? null;
        $this->assertIsArray($tick);
        $this->assertTrue($tick['ok']);

        return $tick;
    }
}
