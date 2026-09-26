<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Services;

use App\Models\User;
use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\Models\Employee;
use App\Modules\People\Services\PeopleScope;
use App\Modules\Pulse\Contracts\ResponseRepository;
use App\Modules\Pulse\Contracts\SurveyRepository;
use App\Modules\Pulse\Enums\WaveStatus;
use App\Modules\Pulse\Exceptions\PulseException;
use App\Modules\Pulse\Models\SurveyResponse;
use App\Modules\Pulse\Models\SurveyWave;
use App\Modules\Pulse\Support\AnswerValidator;
use App\Modules\Pulse\Support\Participation;
use App\Modules\Pulse\Support\RespondentHash;
use App\Modules\Pulse\Support\SafeSegments;
use App\Modules\Pulse\Support\WaveAudience;
use App\Modules\Pulse\Support\WaveResults;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

/**
 * Answering and reporting.
 *
 * Anonymity guarantees (server side, not UI):
 * 1. An anonymous wave stores no employee id: only an HMAC token (RespondentHash) to refuse a second answer, the
 *    respondent's branch/department (for breakdowns) and the day. The salt behind the token is wiped at close.
 * 2. Before an anonymous wave is closed only coarse participation is shown (no live results to diff).
 * 3. Results are aggregates only; a group (the wave, a question, a manager's department) smaller than the wave's
 *    minimum (at least 5 for anonymous waves) is suppressed; a branch/department is listed only when it and the
 *    rest of the wave are both at least the minimum (SafeSegments), so no group can be obtained by subtraction.
 * 4. Individual responses are listed only for non-anonymous waves (admins).
 * Who reads results: admins — everything; managers — their own department of non-lifecycle waves (same minimum).
 */
final readonly class ResponseService
{
    public const int RESPONSES_LIMIT = 1000;

    public function __construct(
        private SurveyRepository $surveys,
        private ResponseRepository $responses,
        private PeopleScope $scope,
        private RespondentHash $hash,
        private EmployeeRepository $employees,
    ) {}

    /**
     * Open waves the user is asked in, with "responded".
     *
     * @return list<array{wave: SurveyWave, responded: bool}>
     */
    public function mine(User $user): array
    {
        $employee = $this->scope->employeeOf($user);
        if ($employee === null) {
            return [];
        }
        $out = [];
        foreach ($this->surveys->openWaves() as $wave) {
            if ($wave->salt !== null && WaveAudience::includes($wave, $employee)) {
                $token = $this->hash->for($wave->salt, $employee->id);
                $out[] = ['wave' => $wave, 'responded' => $this->responses->answeredHashes($wave->id, [$token]) !== []];
            }
        }

        return $out;
    }

    /**
     * The wave to answer (open, the user in its audience).
     *
     * @return array{wave: SurveyWave, employee: Employee, responded: bool}
     *
     * @throws PulseException no_employee | wave_not_open | not_in_audience
     */
    public function form(User $user, int $waveId): array
    {
        $employee = $this->scope->employeeOf($user) ?? throw PulseException::noEmployee();
        $wave = $this->surveys->findWave($waveId) ?? throw (new ModelNotFoundException)->setModel(SurveyWave::class, [$waveId]);
        if ($wave->status !== WaveStatus::Open || $wave->salt === null) {
            throw PulseException::waveNotOpen();
        }
        if (! WaveAudience::includes($wave, $employee)) {
            throw PulseException::notInAudience();
        }
        $token = $this->hash->for($wave->salt, $employee->id);

        return ['wave' => $wave, 'employee' => $employee, 'responded' => $this->responses->answeredHashes($wave->id, [$token]) !== []];
    }

    /**
     * @param  array<string, mixed>  $answers
     *
     * @throws PulseException no_employee | wave_not_open | not_in_audience | invalid_answers | already_responded
     */
    public function respond(User $user, int $waveId, array $answers, ?Carbon $now = null): void
    {
        ['wave' => $wave, 'employee' => $employee, 'responded' => $responded] = $this->form($user, $waveId);
        if ($responded) {
            throw PulseException::alreadyResponded();
        }
        $clean = AnswerValidator::validate($wave->survey->questions, $answers);
        $created = $this->responses->createOnce([
            'wave_id' => $wave->id,
            'respondent_hash' => $this->hash->for($wave->salt, $employee->id),
            'employee_id' => $wave->anonymous ? null : $employee->id,
            'branch_id' => $employee->branch_id,
            'department_id' => $employee->department_id,
            'answers' => $clean,
            'submitted_on' => ($now ?? Carbon::now())->toDateString(),
        ]);
        if (! $created) {
            throw PulseException::alreadyResponded();
        }
    }

    /**
     * Aggregated results. Before an anonymous wave is closed (and, for managers, before any wave is closed) only
     * coarse participation is returned, no scores and no texts: comparing two live snapshots would reveal the answer
     * of whoever answered in between. Admins may break closed results down by branch or department.
     *
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    public function results(User $user, SurveyWave $wave, ?string $segment): array
    {
        $department = $this->departmentScope($user, $wave);
        $rows = $this->responses->answersOf($wave->id, $department);
        $scope = ['scope' => $department === null ? 'all' : 'department'];
        if (! $this->revealed($wave, $department)) {
            return $scope + $this->participation($wave, count($rows), $department);
        }
        $questions = $wave->survey->questions;
        $out = $scope + ['state' => $wave->status->value]
            + WaveResults::summary($questions, array_column($rows, 'answers'), $wave->min_group_size);
        if ($department === null && ($segment === 'branch' || $segment === 'department')) {
            $out['segments'] = $this->segments($rows, $segment.'_id', $questions, $wave->min_group_size);
        }

        return $out;
    }

    /**
     * Wave-over-wave: the headline number of every numeric question (scale average, eNPS) for this wave and the
     * previous revealed one (or $with), overall and per branch/department. A segment is listed only where
     * SafeSegments allows it in that wave (its size and the rest of the wave both at least the minimum group);
     * otherwise it is left out entirely: no name, no count.
     *
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    public function compare(User $user, SurveyWave $wave, ?SurveyWave $with, string $segment): array
    {
        $department = $this->departmentScope($user, $wave);
        if (! $this->revealed($wave, $department)) {
            return ['scope' => $department === null ? 'all' : 'department']
                + $this->participation($wave, count($this->responses->answersOf($wave->id, $department)), $department);
        }
        $previous = $with ?? $this->surveys->previousWave($wave);
        if ($previous !== null && ($previous->survey_id !== $wave->survey_id || ! $this->revealed($previous, $department))) {
            $previous = null;
        }
        $questions = array_values(array_filter(
            $wave->survey->questions,
            static fn (array $q): bool => in_array($q['type'], ['scale5', 'scale10', 'enps'], true),
        ));
        $current = $this->responses->answersOf($wave->id, $department);
        $before = $previous === null ? [] : $this->responses->answersOf($previous->id, $department);
        $key = $segment === 'branch' ? 'branch_id' : 'department_id';

        $rows = [[
            'segment' => null,
            'name' => null,
            'questions' => array_map(fn (array $q): array => $this->delta($q, $current, $before, $wave, $previous), $questions),
        ]];
        if ($department === null) {
            $now = SafeSegments::allowed(self::groupBy($current, $key), $wave->min_group_size, count($current));
            $then = $previous === null ? [] : SafeSegments::allowed(self::groupBy($before, $key), $previous->min_group_size, count($before));
            $ids = array_values(array_unique([...array_keys($now), ...array_keys($then)]));
            sort($ids);
            $names = $this->responses->segmentNames($segment, $ids);
            foreach ($ids as $id) {
                $rows[] = [
                    'segment' => $id,
                    'name' => $names[$id] ?? null,
                    'questions' => array_map(fn (array $q): array => $this->delta($q, $now[$id] ?? [], $then[$id] ?? [], $wave, $previous), $questions),
                ];
            }
        }

        return [
            'scope' => $department === null ? 'all' : 'department',
            'state' => $wave->status->value,
            'segment' => $segment,
            'current' => ['id' => $wave->id, 'starts_at' => $wave->starts_at->toIso8601String()],
            'previous' => $previous === null ? null : ['id' => $previous->id, 'starts_at' => $previous->starts_at->toIso8601String()],
            'questions' => array_map(static fn (array $q): array => ['id' => $q['id'], 'type' => $q['type'], 'text' => $q['text']], $questions),
            'rows' => $rows,
        ];
    }

    /**
     * @return Collection<int, SurveyResponse>
     *
     * @throws PulseException anonymous_wave
     */
    public function identified(SurveyWave $wave): Collection
    {
        if ($wave->anonymous) {
            throw PulseException::anonymousWave();
        }

        return $this->responses->identified($wave->id, self::RESPONSES_LIMIT);
    }

    /**
     * null = everything (admin); an id = the manager's own department; otherwise not allowed.
     *
     * @throws AuthorizationException
     */
    private function departmentScope(User $user, SurveyWave $wave): ?int
    {
        if ($this->scope->isAdmin($user)) {
            return null;
        }
        $ctx = $this->scope->for($user);
        $self = $this->scope->employeeOf($user);
        if (! $ctx->isManager() || $self?->department_id === null || $wave->isLifecycle()) {
            throw new AuthorizationException;
        }

        return $self->department_id;
    }

    /**
     * Whether aggregates may be shown: a closed wave always; an open non-anonymous wave only to admins (they may
     * read those answers one by one anyway). Anonymous waves, and managers: only after closing.
     */
    private function revealed(SurveyWave $wave, ?int $department): bool
    {
        return $wave->status === WaveStatus::Closed || (! $wave->anonymous && $department === null);
    }

    /** @return array{state: string, suppressed: bool, responses: null, questions: array{}, participation: array{responded_bucket: string, responded_percent: int|null}} */
    private function participation(SurveyWave $wave, int $responses, ?int $department): array
    {
        $audience = $this->employees->working()->filter(
            static fn (Employee $e): bool => WaveAudience::includes($wave, $e) && ($department === null || $e->department_id === $department),
        )->count();

        return [
            'state' => $wave->status->value,
            'suppressed' => true,
            'responses' => null,
            'questions' => [],
            'participation' => Participation::of($responses, $audience),
        ];
    }

    /**
     * @param  list<array{answers: array<string, mixed>, branch_id: int|null, department_id: int|null}>  $rows
     * @return array<int, list<array{answers: array<string, mixed>, branch_id: int|null, department_id: int|null}>> segment id to rows; rows without a segment stay only in the total
     */
    private static function groupBy(array $rows, string $key): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $id = $row[$key] ?? null;
            if ($id !== null) {
                $groups[(int) $id][] = $row;
            }
        }

        return $groups;
    }

    /**
     * Segments that may be shown (SafeSegments); the rest are not listed at all.
     *
     * @param  list<array{answers: array<string, mixed>, branch_id: int|null, department_id: int|null}>  $rows
     * @param  list<array<string, mixed>>  $questions
     * @return list<array<string, mixed>>
     */
    private function segments(array $rows, string $key, array $questions, int $minGroup): array
    {
        $allowed = SafeSegments::allowed(self::groupBy($rows, $key), max(1, $minGroup), count($rows));
        ksort($allowed);
        $names = $this->responses->segmentNames(str_replace('_id', '', $key), array_keys($allowed));
        $out = [];
        foreach ($allowed as $id => $group) {
            $out[] = ['segment' => $id, 'name' => $names[$id] ?? null]
                + WaveResults::summary($questions, array_column($group, 'answers'), $minGroup);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  list<array{answers: array<string, mixed>, branch_id: int|null, department_id: int|null}>  $now
     * @param  list<array{answers: array<string, mixed>, branch_id: int|null, department_id: int|null}>  $then
     * @return array{id: string, current: float|null, previous: float|null, delta: float|null}
     */
    private function delta(array $question, array $now, array $then, SurveyWave $wave, ?SurveyWave $previous): array
    {
        $current = count($now) >= max(1, $wave->min_group_size) ? WaveResults::headline($question, array_column($now, 'answers')) : null;
        $before = $previous !== null && count($then) >= max(1, $previous->min_group_size)
            ? WaveResults::headline($question, array_column($then, 'answers')) : null;

        return [
            'id' => (string) $question['id'],
            'current' => $current,
            'previous' => $before,
            'delta' => $current === null || $before === null ? null : round($current - $before, 2),
        ];
    }
}
