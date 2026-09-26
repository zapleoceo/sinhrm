<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\People\Models\Employee;
use App\Modules\Pulse\Models\Survey;
use App\Modules\Pulse\Models\SurveyWave;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Builders for Pulse tests (uses PeopleFixtures). Synthetic data only. */
trait PulseFixtures
{
    /** @var list<array<string, mixed>> */
    protected array $questions = [
        ['id' => 'enps', 'type' => 'enps', 'text' => 'Recommend us?', 'required' => true],
        ['id' => 'q1', 'type' => 'scale5', 'text' => 'I know what is expected of me', 'required' => true],
        ['id' => 'pick', 'type' => 'single', 'text' => 'Best thing', 'options' => ['Team', 'Tasks', 'Pay'], 'required' => false],
        ['id' => 'tags', 'type' => 'multi', 'text' => 'Tags', 'options' => ['A', 'B', 'C'], 'required' => false],
        ['id' => 'txt', 'type' => 'text', 'text' => 'Anything else?', 'required' => false],
    ];

    /** @param  array<string, mixed>  $attributes */
    protected function survey(array $attributes = []): Survey
    {
        return Survey::query()->create($attributes + ['title' => 'Pulse sample', 'type' => 'engagement', 'questions' => $this->questions]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function wave(Survey $survey, array $attributes = []): SurveyWave
    {
        return SurveyWave::query()->create($attributes + [
            'survey_id' => $survey->id,
            'schedule' => 'once',
            'audience' => ['branch_ids' => [], 'department_ids' => []],
            'anonymous' => true,
            'min_group_size' => 5,
            'starts_at' => Carbon::now()->subDay(),
            'ends_at' => Carbon::now()->addDays(6),
            'status' => 'open',
            'salt' => Str::random(48),
        ]);
    }

    /**
     * Employees with logins.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<Employee>
     */
    protected function people(int $count, array $attributes = []): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $this->employee($attributes, $this->login());
        }

        return $out;
    }

    /** @param  array<string, mixed>  $answers */
    protected function answer(SurveyWave $wave, Employee $employee, array $answers): void
    {
        $this->actingAs($this->userOf($employee))->postJson("/api/pulse/waves/{$wave->id}/responses", ['answers' => $answers])->assertCreated();
    }

    /** @return array<string, mixed> the "pulse.tick" part of the ops answer */
    protected function pulseTick(): array
    {
        config(['ops.secret' => 'test-secret']);
        $jobs = $this->postJson('/api/ops/jobs/run', [], ['X-Ops-Secret' => 'test-secret'])->assertOk()->json('jobs');
        $this->assertIsArray($jobs);
        $tick = $jobs['pulse.tick'] ?? null;
        $this->assertIsArray($tick);
        $this->assertTrue($tick['ok']);

        return $tick;
    }
}
