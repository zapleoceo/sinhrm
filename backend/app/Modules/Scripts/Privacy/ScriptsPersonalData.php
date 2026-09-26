<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Privacy;

use App\Modules\Core\Contracts\PersonalDataProvider;
use App\Modules\Core\DTO\DataSubject;
use App\Modules\Core\Enums\DataSubjectType;
use App\Modules\Scripts\Models\ScriptEvaluation;
use App\Modules\Scripts\Models\Task;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scripts' share: recruiter tasks about the person and script evaluations of the candidate's calls/messages
 * (they quote the conversation). Erase deletes the evaluations and renames the tasks; task rows stay (workload stats).
 */
final readonly class ScriptsPersonalData implements PersonalDataProvider
{
    public function section(): string
    {
        return 'tasks_and_evaluations';
    }

    public function blocker(DataSubject $subject, bool $erase): ?string
    {
        return null;
    }

    public function export(DataSubject $subject): array
    {
        return [
            'tasks' => $this->tasks($subject)->orderBy('id')->get()->map(static fn (Task $t): array => [
                'title' => $t->title,
                'type' => $t->type->value,
                'due_at' => $t->due_at->toIso8601String(),
                'done_at' => $t->done_at?->toIso8601String(),
            ])->all(),
            'script_evaluations' => $this->evaluations($subject)->orderBy('id')->get()->map(static fn (ScriptEvaluation $e): array => [
                'score' => $e->score,
                'engine' => $e->engine->value,
                'result' => $e->result,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    public function erase(DataSubject $subject): array
    {
        $label = $subject->type === DataSubjectType::Candidate ? 'Видалений кандидат #' : 'Видалений співробітник #';

        return [
            'tasks' => $this->tasks($subject)->update(['title' => $label.$subject->id, 'link' => null]),
            'script_evaluations' => $this->evaluations($subject)->delete(),
        ];
    }

    /** @return Builder<Task> */
    private function tasks(DataSubject $subject): Builder
    {
        $column = $subject->type === DataSubjectType::Candidate ? 'candidate_id' : 'employee_id';

        return Task::query()->where($column, $subject->id);
    }

    /** @return Builder<ScriptEvaluation> */
    private function evaluations(DataSubject $subject): Builder
    {
        return $subject->type === DataSubjectType::Candidate
            ? ScriptEvaluation::query()->whereHas('touchpoint', static fn (Builder $q): Builder => $q->where('candidate_id', $subject->id))
            : ScriptEvaluation::query()->whereRaw('1 = 0');
    }
}
