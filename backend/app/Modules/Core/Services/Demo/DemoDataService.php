<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Demo;

use App\Models\User;
use App\Modules\Pulse\Models\SurveyWave;
use App\Modules\Pulse\Services\ResponseService;
use App\Modules\Pulse\Services\WaveLifecycle;
use App\Modules\Recruiting\DTO\VacancyData;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Services\RecruitingDemoData;
use App\Modules\Recruiting\Services\VacancyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Company-wide synthetic data for the report charts (POST /api/ops/demo-fill?confirm=demo&step=<name>): structure,
 * ~60 people with pay, recruiting funnel with channel costs and touches, time off, timesheets, OKR, 1:1s, a closed 360
 * cycle, two closed Pulse waves, mood, Desk, knowledge, assets, hiring requests, script scores — the last 6 months.
 *
 * The fill is split into ordered STEPS; each runs in its own transaction (one HTTP request, well under the 60 s
 * function limit on Neon), is done at most once (marker "step:<name>" in demo_records) and reads what earlier steps
 * created from the registry. Plain data goes in bulk inserts (chunks of 500); Recruiting and Pulse go through their
 * own services (stage history, captured touches, anonymous responses, membership snapshot on close).
 *
 * Rules: every name/title starts with "[ТЕСТ]", e-mails are on the reserved example.test domain; every root row is
 * listed in demo_records (DemoRegistry), reset deletes only those. Randomness is a seeded Mt19937 per step (Faker is
 * a dev dependency, absent on deploys).
 */
final class DemoDataService
{
    public const string PREFIX = '[ТЕСТ] ';

    public const array STEPS = [
        'org', 'people', 'recruiting-setup',
        'recruiting-1', 'recruiting-2', 'recruiting-3', 'recruiting-4', 'recruiting-5', 'recruiting-6', 'recruiting-7', 'recruiting-8',
        'recruiting-9', 'recruiting-10', 'recruiting-11', 'recruiting-12', 'recruiting-13', 'recruiting-14', 'recruiting-15',
        'touchpoints-1', 'touchpoints-2', 'touchpoints-3', 'scripts',
        'timeoff', 'time', 'perform', 'pulse-1', 'pulse-2', 'mood', 'desk', 'knowledge', 'assets', 'hiring',
    ];

    private const int SEED = 20260928;

    private const int CANDIDATES_PER_STEP = 10;

    private const int TOUCHES_TARGET = 600;

    private const int CHUNK = 500;

    private const array FIRST_F = ['Олена', 'Марія', 'Ірина', 'Наталія', 'Юлія', 'Тетяна', 'Софія', 'Катерина', 'Анна', 'Вікторія', 'Оксана', 'Дарина'];

    private const array FIRST_M = ['Андрій', 'Дмитро', 'Олег', 'Сергій', 'Максим', 'Богдан', 'Віктор', 'Роман', 'Ігор', 'Павло', 'Тарас', 'Євген'];

    private const array LAST = ['Коваленко', 'Бондаренко', 'Ткаченко', 'Кравченко', 'Олійник', 'Шевчук', 'Поліщук', 'Савченко', 'Руденко', 'Марченко', 'Мороз', 'Лисенко', 'Гончаренко', 'Павленко'];

    private const array CITIES = ['Київ', 'Львів', 'Дніпро'];

    private const array BRANCHES = ['Київ Центр', 'Львів', 'Дніпро'];

    private const array DEPARTMENTS = ['Продажі', 'Навчання', 'Адміністрація', 'Маркетинг'];

    /** position => base monthly pay, UAH (index = level) */
    private const array POSITIONS = ['Керівник відділу' => 62000, 'Старший спеціаліст' => 42000, 'Спеціаліст' => 30000, 'Молодший спеціаліст' => 22000];

    private const string ADMIN_EMAIL = 'demo-admin@example.test';

    private Randomizer $rnd;

    private Carbon $now;

    /** @var array<string, int> */
    private array $counts = [];

    public function __construct(
        private readonly DemoRegistry $registry,
        private readonly RecruitingDemoData $recruiting,
        private readonly VacancyService $vacancies,
        private readonly WaveLifecycle $waves,
        private readonly ResponseService $responses,
        private readonly PermissionRegistrar $permissions,
    ) {}

    /** @return list<string> */
    public function steps(): array
    {
        return self::STEPS;
    }

    /** @return list<string> steps already done */
    public function done(): array
    {
        return array_values(array_filter(self::STEPS, fn (string $s): bool => $this->registry->has($this->marker($s))));
    }

    /**
     * Runs one step in its own transaction; a done step is a no-op.
     *
     * @return array{step: string, already: bool, counts: array<string, int>}
     *
     * @throws InvalidArgumentException unknown step
     * @throws RuntimeException an earlier step is not done yet
     */
    public function run(string $step): array
    {
        $index = array_search($step, self::STEPS, true);
        if ($index === false) {
            throw new InvalidArgumentException('unknown_step');
        }
        if ($this->registry->has($this->marker($step))) {
            return ['step' => $step, 'already' => true, 'counts' => []];
        }
        foreach (array_slice(self::STEPS, 0, (int) $index) as $previous) {
            if (! $this->registry->has($this->marker($previous))) {
                throw new RuntimeException('previous_step_missing:'.$previous);
            }
        }
        $this->rnd = new Randomizer(new Mt19937(self::SEED + (int) $index));
        $this->now = Carbon::now()->startOfMinute();
        $this->counts = [];
        DB::transaction(function () use ($step): void {
            $this->dispatch($step);
            $this->registry->add($this->marker($step), 0);
            $this->registry->flush();
        });

        return ['step' => $step, 'already' => false, 'counts' => $this->counts];
    }

    /** @return array<string, int> */
    public function reset(): array
    {
        return DB::transaction(fn (): array => $this->registry->purge());
    }

    private function dispatch(string $step): void
    {
        if (preg_match('/^recruiting-(\d+)$/', $step, $m) === 1) {
            $this->candidates((int) $m[1] - 1);

            return;
        }
        if (str_starts_with($step, 'touchpoints-')) {
            $this->touches();

            return;
        }
        if (preg_match('/^pulse-(\d)$/', $step, $m) === 1) {
            $this->pulse((int) $m[1] - 1);

            return;
        }
        match ($step) {
            'org' => $this->structure(),
            'people' => $this->people(),
            'recruiting-setup' => $this->recruitingSetup(),
            'scripts' => $this->scripts(),
            'timeoff' => $this->timeOff(),
            'time' => $this->timesheets(),
            'perform' => $this->perform(),
            'mood' => $this->mood(),
            'desk' => $this->desk(),
            'knowledge' => $this->knowledge(),
            'assets' => $this->assets(),
            'hiring' => $this->hiring(),
            default => throw new InvalidArgumentException('unknown_step'),
        };
    }

    // ---------------------------------------------------------------- context from earlier steps

    /** @return array{branches: list<int>, departments: list<int>, positions: list<int>} */
    private function org(): array
    {
        return [
            'branches' => $this->registry->ids('branches'),
            'departments' => $this->registry->ids('departments'),
            'positions' => $this->registry->ids('positions'),
        ];
    }

    /** @return list<array{id: int, user_id: int, branch: int, department: int, manager: int|null, active: bool}> */
    private function employees(): array
    {
        $ids = $this->registry->ids('employees');

        return array_values(DB::table('employees')->whereIn('id', $ids)->orderBy('id')->get()->map(static fn (object $e): array => [
            'id' => (int) $e->id, 'user_id' => (int) $e->user_id, 'branch' => (int) $e->branch_id, 'department' => (int) $e->department_id,
            'manager' => $e->manager_id === null ? null : (int) $e->manager_id, 'active' => $e->status !== 'terminated',
        ])->all());
    }

    /** @return list<array{id: int, user_id: int, branch: int, department: int, manager: int|null, active: bool}> */
    private function active(): array
    {
        return array_values(array_filter($this->employees(), static fn (array $e): bool => $e['active']));
    }

    private function admin(): User
    {
        return User::query()->where('email', self::ADMIN_EMAIL)->firstOrFail();
    }

    /** @return list<User> */
    private function recruiters(): array
    {
        return array_values(User::query()->whereIn('email', ['demo-hr-1@example.test', 'demo-hr-2@example.test', 'demo-hr-3@example.test'])->orderBy('email')->get()->all());
    }

    // ---------------------------------------------------------------- org & people

    private function structure(): void
    {
        $cities = [];
        foreach (self::CITIES as $name) {
            $cities[] = $this->insert('cities', ['name' => self::PREFIX.$name, 'status' => 'active']);
        }
        foreach (self::BRANCHES as $i => $name) {
            $this->insert('branches', ['name' => self::PREFIX.$name, 'status' => 'active', 'city_id' => $cities[$i]]);
        }
        foreach (self::DEPARTMENTS as $name) {
            $this->insert('departments', ['name' => self::PREFIX.$name, 'status' => 'active']);
        }
        foreach (array_keys(self::POSITIONS) as $name) {
            $this->insert('positions', ['name' => self::PREFIX.$name, 'status' => 'active']);
        }
    }

    /**
     * Users (bulk, roles and branches bulk too) and 3 branches × 4 departments × 5 employees; the first of each cell is
     * its head (reports to the director = #0), genders alternate so every department has both genders ≥ 5. Six people
     * left during the last months.
     */
    private function people(): void
    {
        $org = $this->org();
        $users = [['email' => self::ADMIN_EMAIL, 'name' => self::PREFIX.'Адміністратор', 'role' => 'admin', 'branches' => $org['branches']]];
        foreach ([1, 2, 3] as $n) {
            $users[] = ['email' => "demo-hr-{$n}@example.test", 'name' => self::PREFIX.'Рекрутер '.$n, 'role' => 'recruiter', 'branches' => [$org['branches'][$n - 1]]];
        }
        $positionPay = array_values(self::POSITIONS);
        $employees = [];
        $n = 0;
        foreach ($org['branches'] as $branch) {
            foreach ($org['departments'] as $d => $department) {
                for ($k = 0; $k < 5; $k++, $n++) {
                    $female = ($n + $d) % 2 === 0;
                    $email = sprintf('demo-emp-%02d@example.test', $n + 1);
                    $name = self::PREFIX.self::LAST[$n % count(self::LAST)].' '.($female ? self::FIRST_F : self::FIRST_M)[($n * 5) % 12];
                    $users[] = ['email' => $email, 'name' => $name, 'role' => 'employee', 'branches' => [$branch]];
                    $level = $k === 0 ? 0 : min(3, 1 + $this->rnd->getInt(0, 2));
                    $terminated = $n % 10 === 7;
                    $hired = $this->now->copy()->subDays($n % 6 === 1 ? $this->rnd->getInt(10, 175) : $this->rnd->getInt(200, 1500));
                    $employees[] = ['n' => $n, 'k' => $k, 'email' => $email, 'name' => $name, 'female' => $female, 'level' => $level,
                        'terminated' => $terminated, 'hired' => $hired, 'branch' => $branch, 'department' => $department,
                        'pay' => $positionPay[$level] * ($female ? 0.93 : 1.0) * (0.9 + $this->rnd->getInt(0, 20) / 100),
                        'birth' => $this->now->copy()->subYears(22 + $this->rnd->getInt(0, 30))->subDays($this->rnd->getInt(0, 364))->toDateString(),
                        'fired' => $terminated ? $this->now->copy()->subDays($this->rnd->getInt(5, 170))->toDateString() : null,
                        'raise' => $this->now->copy()->subDays($this->rnd->getInt(10, 150))];
                }
            }
        }

        $this->bulk('users', array_map(fn (array $u): array => [
            'email' => $u['email'], 'name' => $u['name'], 'status' => 'active', 'approval_emails' => false,
            'created_at' => $this->now, 'updated_at' => $this->now,
        ], $users));
        /** @var array<string, int> $userIds */
        $userIds = array_map('intval', DB::table('users')->whereIn('email', array_column($users, 'email'))->pluck('id', 'email')->all());
        $this->registerAll('users', array_values($userIds));
        /** @var array<string, int> $roles */
        $roles = array_map('intval', DB::table('roles')->where('guard_name', 'web')->pluck('id', 'name')->all());
        $roleRows = [];
        $branchRows = [];
        foreach ($users as $u) {
            if (isset($roles[$u['role']])) {
                $roleRows[] = ['role_id' => $roles[$u['role']], 'model_type' => User::class, 'model_id' => $userIds[$u['email']]];
            }
            foreach ($u['branches'] as $b) {
                $branchRows[] = ['user_id' => $userIds[$u['email']], 'branch_id' => $b, 'created_at' => $this->now, 'updated_at' => $this->now];
            }
        }
        $this->bulk('model_has_roles', $roleRows);
        $this->bulk('branch_user', $branchRows);
        $this->permissions->forgetCachedPermissions();

        $this->bulk('employees', array_map(fn (array $e): array => [
            'user_id' => $userIds[$e['email']], 'full_name' => $e['name'], 'work_email' => $e['email'],
            'phone' => sprintf('+38050%07d', 3000000 + $e['n'] * 7727), 'birth_date' => $e['birth'],
            'hired_at' => $e['hired']->toDateString(), 'fired_at' => $e['fired'], 'termination_reason' => $e['terminated'] ? 'Власне бажання' : null,
            'status' => $e['terminated'] ? 'terminated' : ($e['n'] % 17 === 4 ? 'on_leave' : 'active'),
            'employment_type' => $e['n'] % 9 === 5 ? 'part_time' : 'full_time',
            'branch_id' => $e['branch'], 'department_id' => $e['department'], 'position_id' => $org['positions'][$e['level']],
            'gender' => $e['female'] ? 'female' : 'male', 'created_at' => $e['hired'], 'updated_at' => $this->now,
        ], $employees));
        /** @var array<string, int> $employeeIds */
        $employeeIds = array_map('intval', DB::table('employees')->whereIn('work_email', array_column($employees, 'email'))->pluck('id', 'work_email')->all());
        $this->registerAll('employees', array_values($employeeIds));

        // Managers: the director (#0) leads the heads, each head leads the rest of the cell.
        $director = $employeeIds[$employees[0]['email']];
        $heads = [];
        foreach (array_chunk($employees, 5) as $cell) {
            $head = $employeeIds[$cell[0]['email']];
            $heads[] = $head;
            $members = array_map(static fn (array $e): int => $employeeIds[$e['email']], array_slice($cell, 1));
            DB::table('employees')->whereIn('id', $members)->update(['manager_id' => $head]);
        }
        DB::table('employees')->whereIn('id', array_values(array_diff($heads, [$director])))->update(['manager_id' => $director]);

        $pay = [];
        foreach ($employees as $e) {
            $id = $employeeIds[$e['email']];
            $pay[] = ['employee_id' => $id, 'amount' => round($e['pay'] * 0.9, -2), 'currency' => 'UAH', 'period' => 'month',
                'effective_on' => $e['hired']->toDateString(), 'reason' => 'Прийом', 'created_at' => $e['hired'], 'updated_at' => $e['hired']];
            if ($e['n'] % 3 === 0) {
                $pay[] = ['employee_id' => $id, 'amount' => round($e['pay'], -2), 'currency' => 'UAH', 'period' => 'month',
                    'effective_on' => $e['raise']->toDateString(), 'reason' => 'Перегляд', 'created_at' => $e['raise'], 'updated_at' => $e['raise']];
            }
        }
        $this->bulk('employee_compensations', $pay);
    }

    // ---------------------------------------------------------------- recruiting

    private function recruitingSetup(): void
    {
        $org = $this->org();
        $admin = $this->admin();
        $recruiters = $this->recruiters();
        $titles = ['Менеджер з продажу', 'Викладач англійської', 'Адміністратор філії', 'SMM-менеджер', 'Координатор навчання', 'Бухгалтер', 'Менеджер з продажу (вечірня зміна)', 'Методист'];
        foreach ($titles as $i => $title) {
            $vacancy = $this->vacancies->create($admin, new VacancyData([
                'title' => self::PREFIX.$title,
                'branch_id' => $org['branches'][$i % 3],
                'department_id' => $org['departments'][$i % 4],
                'position_id' => $org['positions'][2 + $i % 2],
                'recruiter_id' => $recruiters[$i % 3]->id,
                'status' => $i === 7 ? 'paused' : 'open',
                'description' => 'Тестова вакансія (синтетичні дані).',
            ]));
            $this->registry->add('vacancies', $vacancy->id);
            $this->count('vacancies');
            $vacancy->forceFill(['created_at' => $this->now->copy()->subDays(185 - $i * 12)])->saveQuietly();
        }

        $note = self::PREFIX.'витрати';
        $costs = [];
        foreach ($this->channels() as $c => $channel) {
            for ($m = 5; $m >= 0; $m--) {
                $month = $this->now->copy()->startOfMonth()->subMonths($m);
                $costs[] = ['channel_id' => $channel, 'period_start' => $month->toDateString(), 'period_end' => $month->copy()->endOfMonth()->toDateString(),
                    'amount' => 2000 + 1500 * $c + $this->rnd->getInt(0, 40) * 100, 'currency' => 'UAH', 'note' => $note,
                    'created_at' => $month, 'updated_at' => $month];
            }
        }
        $this->bulk('acquisition_channel_costs', $costs);
        $this->registerAll('acquisition_channel_costs', array_map('intval', DB::table('acquisition_channel_costs')->where('note', $note)->pluck('id')->all()));
    }

    /** @return list<int> */
    private function channels(): array
    {
        return array_values(array_map('intval', DB::table('acquisition_channels')->where('active', true)->orderBy('id')->limit(6)->pluck('id')->all()));
    }

    /** One slice of 10 candidate stories through the Recruiting services. */
    private function candidates(int $slice): void
    {
        $vacancies = array_values(Vacancy::query()->whereIn('id', $this->registry->ids('vacancies'))->orderBy('id')->get()->all());
        $stories = $this->recruiting->populate($this->recruiters(), $vacancies, self::CANDIDATES_PER_STEP, 180, self::PREFIX, $this->channels(), 500, $slice * self::CANDIDATES_PER_STEP);
        foreach ($stories as $story) {
            $this->registry->add('candidates', $story['candidate']);
            $this->count('candidates');
            $this->counts['touchpoints'] = ($this->counts['touchpoints'] ?? 0) + $story['touches'];
        }
    }

    /** Extra touches over the last 90 days until the demo candidates have TOUCHES_TARGET (≤ 60 per step). */
    private function touches(): void
    {
        $ids = $this->registry->ids('candidates');
        $have = DB::table('touchpoints')->whereIn('candidate_id', $ids)->count();
        $models = array_values(Candidate::query()->whereIn('id', $ids)->orderBy('id')->get()->all());
        $recruiters = $this->recruiters();
        $todo = min(60, self::TOUCHES_TARGET - $have);
        for ($t = 0; $t < $todo && $models !== []; $t++) {
            $n = $have + $t;
            $at = $this->now->copy()->subMinutes($this->rnd->getInt(60, 90 * 24 * 60));
            $this->recruiting->extraTouch($models[$n % count($models)], $recruiters[$n % 3], $n, $at);
            $this->count('touchpoints');
        }
    }

    private function scripts(): void
    {
        $script = $this->insert('scripts', ['name' => self::PREFIX.'Перший дзвінок кандидату', 'channel' => 'call', 'archived' => false, 'created_at' => $this->now, 'updated_at' => $this->now]);
        $version = $this->insert('script_versions', [
            'script_id' => $script, 'version' => 1, 'published_at' => $this->now->copy()->subDays(120),
            'steps' => json_encode([['id' => 's1', 'title' => 'Привітання'], ['id' => 's2', 'title' => 'Умови'], ['id' => 's3', 'title' => 'Наступний крок']]),
            'objections' => '[]', 'templates' => '[]', 'followups' => '[]', 'next_step_patterns' => '{}',
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
        DB::table('scripts')->where('id', $script)->update(['active_version_id' => $version]);
        $calls = DB::table('touchpoints')->whereIn('candidate_id', $this->registry->ids('candidates'))->where('channel', 'call')->orderBy('id')->limit(80)->pluck('id')->all();
        $rows = [];
        foreach ($calls as $i => $touchpoint) {
            $score = $this->rnd->getInt(45, 100);
            $rows[] = ['touchpoint_id' => $touchpoint, 'script_version_id' => $version, 'engine' => 'rules', 'score' => $score,
                'result' => json_encode(['next_step' => ['fixed' => $score > 65 || $i % 4 === 0]]), 'created_at' => $this->now];
        }
        $this->bulk('script_evaluations', $rows);
    }

    // ---------------------------------------------------------------- time off & time

    private function timeOff(): void
    {
        $types = array_values(array_map('intval', DB::table('leave_types')->where('active', true)->orderBy('id')->pluck('id')->all()));
        if ($types === []) {
            return;
        }
        $admin = $this->admin()->id;
        $rows = [];
        foreach ($this->employees() as $i => $e) {
            $requests = $i % 3 === 0 ? 2 : 1;
            for ($r = 0; $r < $requests; $r++) {
                $current = $i % 8 === 2 && $r === 0;
                $start = $current ? $this->now->copy()->subDays($this->rnd->getInt(0, 3)) : $this->now->copy()->subDays($this->rnd->getInt(-20, 175));
                $days = $this->rnd->getInt(1, 10);
                $status = $current ? 'approved' : ['approved', 'approved', 'approved', 'pending', 'rejected'][($i + $r) % 5];
                $rows[] = [
                    'employee_id' => $e['id'], 'leave_type_id' => $types[($i + $r) % count($types)],
                    'starts_on' => $start->toDateString(), 'ends_on' => $start->copy()->addDays($days - 1)->toDateString(),
                    'half_day' => 'none', 'days' => $days, 'comment' => self::PREFIX.'відпустка', 'status' => $status,
                    'balance_override' => false, 'approver_id' => $status === 'pending' ? null : $admin,
                    'decided_at' => $status === 'pending' ? null : $start->copy()->subDays(7),
                    'created_by' => $e['user_id'], 'created_at' => $start->copy()->subDays(10), 'updated_at' => $start->copy()->subDays(7),
                ];
            }
        }
        $this->bulk('leave_requests', $rows); // deleted with the demo employees (DemoRegistry::CHILDREN)
    }

    private function timesheets(): void
    {
        $admin = $this->admin()->id;
        $projects = ['Набір студентів', 'Навчальний процес', 'Внутрішні задачі', 'Маркетинг'];
        $sheets = [];
        $hoursOf = [];
        foreach (array_slice($this->employees(), 0, 45) as $i => $e) {
            if (! $e['active']) {
                continue;
            }
            for ($w = 8; $w >= 1; $w--) {
                $week = $this->now->copy()->startOfWeek()->subWeeks($w);
                $hours = [];
                for ($d = 0; $d < 5; $d++) {
                    $hours[] = ($i + $w + $d) % 7 === 0 ? 10 : (($i + $d) % 11 === 0 ? 9 : 8);
                }
                $worked = array_sum($hours);
                $hoursOf[$e['id'].'|'.$week->toDateString()] = [$i, $hours, $week];
                $sheets[] = [
                    'employee_id' => $e['id'], 'week_start' => $week->toDateString(),
                    'status' => $w === 1 ? 'submitted' : 'approved', 'expected_hours' => 40, 'worked_hours' => $worked,
                    'overtime_hours' => max(0, $worked - 40), 'submitted_at' => $week->copy()->addDays(4)->setTime(18, 0),
                    'decided_by' => $w === 1 ? null : $admin, 'decided_at' => $w === 1 ? null : $week->copy()->addDays(7),
                    'created_at' => $week, 'updated_at' => $week->copy()->addDays(4),
                ];
            }
        }
        $this->bulk('timesheets', $sheets);
        $entries = [];
        $ids = [];
        foreach (DB::table('timesheets')->whereIn('employee_id', array_unique(array_column($sheets, 'employee_id')))->get(['id', 'employee_id', 'week_start']) as $row) {
            $key = $row->employee_id.'|'.substr((string) $row->week_start, 0, 10);
            if (! isset($hoursOf[$key])) {
                continue;
            }
            $ids[] = (int) $row->id;
            [$i, $hours, $week] = $hoursOf[$key];
            foreach ($hours as $d => $h) {
                $entries[] = ['timesheet_id' => $row->id, 'date' => $week->copy()->addDays($d)->toDateString(), 'hours' => $h,
                    'project' => $projects[($i + $d) % 4], 'category' => 'work', 'note' => null, 'created_at' => $week, 'updated_at' => $week];
            }
        }
        $this->registerAll('timesheets', $ids);
        $this->bulk('time_entries', $entries);
    }

    // ---------------------------------------------------------------- performance

    private function perform(): void
    {
        $org = $this->org();
        $admin = $this->admin();
        $active = $this->active();
        $quarter = $this->now->year.'-Q'.$this->now->quarter;
        $objectives = [];
        foreach ($org['departments'] as $d => $department) {
            $objectives[] = $this->objective('team', null, $department, $quarter, self::DEPARTMENTS[$d].': ключова ціль кварталу', $admin->id);
        }
        $objectives[] = $this->objective('company', null, null, $quarter, 'Зростання набору на 20%', $admin->id);
        foreach (array_slice($active, 0, 16) as $e) {
            $objectives[] = $this->objective('personal', $e['id'], $e['department'], $quarter, 'Особиста ціль розвитку', $e['user_id']);
        }
        $checkins = [];
        foreach ($objectives as $o => $id) {
            $progress = 0;
            for ($c = 1; $c <= 3; $c++) {
                $after = min(100, $progress + $this->rnd->getInt(5, 35));
                $at = $this->now->copy()->subDays(70 - $c * 20 - $o % 5);
                $checkins[] = ['objective_id' => $id, 'author_id' => $admin->id, 'progress_before' => $progress, 'progress_after' => $after,
                    'key_results' => json_encode([['id' => 'kr1', 'current' => $after]]), 'comment' => 'Оновлення прогресу',
                    'created_at' => $at, 'updated_at' => $at];
                $progress = $after;
            }
            DB::table('objectives')->where('id', $id)->update([
                'progress' => $progress,
                'key_results' => json_encode([['id' => 'kr1', 'title' => 'Ключовий результат', 'start' => 0, 'target' => 100, 'current' => $progress, 'unit' => '%', 'weight' => 1]]),
            ]);
        }
        $this->bulk('objective_checkins', $checkins);

        // 1:1s: every other person with their head, the last 3 months, the next one scheduled.
        $meetings = [];
        foreach ($active as $i => $e) {
            if ($e['manager'] === null || $i % 2 === 1) {
                continue;
            }
            foreach ([80, 45, 10, -7] as $days) {
                $at = $this->now->copy()->subDays($days + $i % 5)->setTime(11, 0);
                $meetings[] = [
                    'manager_employee_id' => $e['manager'], 'employee_id' => $e['id'], 'scheduled_at' => $at,
                    'agenda' => json_encode([['id' => 'a1', 'text' => 'Як справи з задачами?', 'done' => $days > 0]]),
                    'notes_shared' => $days > 0 ? 'Обговорили пріоритети.' : null, 'action_items' => '[]',
                    'status' => $days > 0 ? ($i % 9 === 4 ? 'cancelled' : 'completed') : 'scheduled',
                    'created_by' => $admin->id, 'created_at' => $at->copy()->subDays(7), 'updated_at' => $at,
                ];
            }
        }
        $this->bulk('one_on_ones', $meetings); // deleted with the demo employees
        $this->review($active, $admin->id);
    }

    private function objective(string $scope, ?int $owner, ?int $department, string $period, string $title, int $by): int
    {
        $at = $this->now->copy()->startOfQuarter();

        return $this->insert('objectives', [
            'scope' => $scope, 'owner_employee_id' => $owner, 'department_id' => $scope === 'team' ? $department : null, 'branch_id' => null,
            'period' => $period, 'title' => self::PREFIX.$title, 'description' => null, 'key_results' => '[]', 'progress' => 0,
            'status' => 'active', 'visibility' => 'public', 'created_by' => $by, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    /** @param  list<array{id: int, user_id: int, branch: int, department: int, manager: int|null, active: bool}>  $active */
    private function review(array $active, int $admin): void
    {
        $scale = $this->insert('rating_scales', ['name' => self::PREFIX.'Шкала 1–5', 'levels' => json_encode(array_map(
            static fn (int $v): array => ['value' => $v, 'label' => (string) $v], [1, 2, 3, 4, 5],
        )), 'created_at' => $this->now, 'updated_at' => $this->now]);
        $competencies = [];
        foreach (['Комунікація', 'Відповідальність', 'Командна робота', 'Орієнтація на результат'] as $name) {
            $competencies[] = $this->insert('competencies', ['name' => self::PREFIX.$name, 'scale_id' => $scale, 'active' => true, 'created_at' => $this->now, 'updated_at' => $this->now]);
        }
        $start = $this->now->copy()->subMonths(4)->startOfQuarter();
        $submitted = $start->copy()->addMonths(3)->addDays(7);
        $cycle = $this->insert('review_cycles', [
            'name' => self::PREFIX.'Оцінка 360 '.$start->year.'-Q'.$start->quarter, 'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfQuarter()->toDateString(), 'participants' => json_encode(['branch_ids' => [], 'department_ids' => []]),
            'types' => json_encode(['self', 'manager', 'peer']), 'competency_ids' => json_encode($competencies), 'anonymous' => true,
            'deadlines' => '{}', 'status' => 'closed', 'created_by' => $admin,
            'activated_at' => $start->copy()->addMonths(3), 'closed_at' => $start->copy()->addMonths(3)->addDays(14),
            'created_at' => $start, 'updated_at' => $start->copy()->addMonths(3)->addDays(14),
        ]);
        $assignments = [];
        foreach (array_slice($active, 0, 24) as $i => $subject) {
            $raters = [['self', $subject['id']], ['manager', $subject['manager'] ?? $active[0]['id']],
                ['peer', $active[($i + 1) % count($active)]['id']], ['peer', $active[($i + 2) % count($active)]['id']]];
            foreach ($raters as [$type, $reviewer]) {
                $assignments[] = ['cycle_id' => $cycle, 'subject_employee_id' => $subject['id'], 'reviewer_employee_id' => $reviewer, 'type' => $type,
                    'status' => 'submitted', 'submitted_at' => $submitted, 'created_at' => $start, 'updated_at' => $submitted];
            }
        }
        $this->bulk('review_assignments', $assignments);
        $ids = array_map('intval', DB::table('review_assignments')->where('cycle_id', $cycle)->orderBy('id')->pluck('id')->all());
        $this->registerAll('review_assignments', $ids);
        $answers = [];
        foreach ($ids as $assignment) {
            foreach ($competencies as $competency) {
                $answers[] = ['assignment_id' => $assignment, 'competency_id' => $competency, 'rating' => $this->rnd->getInt(2, 5),
                    'comment' => null, 'created_at' => $submitted, 'updated_at' => $submitted];
            }
        }
        $this->bulk('review_answers', $answers);
    }

    // ---------------------------------------------------------------- pulse & mood

    /** Wave #$wave (0: 100 days ago, 1: 40 days ago): anonymous responses through ResponseService, then the module's close. */
    private function pulse(int $wave): void
    {
        $admin = $this->admin();
        $survey = $this->registry->ids('surveys')[0] ?? $this->insert('surveys', [
            'title' => self::PREFIX.'Пульс залученості', 'type' => 'engagement', 'description' => 'Тестове опитування.',
            'questions' => json_encode([
                ['id' => 'enps', 'type' => 'enps', 'text' => 'Наскільки ймовірно, що ви порекомендуєте нас як роботодавця?', 'required' => true],
                ['id' => 'q1', 'type' => 'scale5', 'text' => 'Я розумію, чого від мене очікують', 'required' => true],
                ['id' => 'q2', 'type' => 'scale5', 'text' => 'Я маю все необхідне для роботи', 'required' => true],
            ]),
            'active' => true, 'created_by' => $admin->id, 'created_at' => $this->now->copy()->subDays(120), 'updated_at' => $this->now,
        ]);
        $starts = $this->now->copy()->subDays($wave === 0 ? 100 : 40);
        $model = $this->waves->create([
            'survey_id' => $survey, 'schedule' => 'once', 'audience' => ['branch_ids' => [], 'department_ids' => []],
            'anonymous' => true, 'min_group_size' => 5, 'starts_at' => $starts, 'ends_at' => $starts->copy()->addDays(7),
            'created_by' => $admin->id,
        ], $this->now);
        $this->registry->add('survey_waves', $model->id);
        $active = $this->active();
        $users = User::query()->whereIn('id', array_column($active, 'user_id'))->get()->keyBy('id');
        $better = $wave === 1 ? 1 : 0; // the second wave is a bit better
        foreach ($active as $i => $e) {
            $user = $users->get($e['user_id']);
            if (! $user instanceof User || ($i + $wave) % 9 === 0) {
                continue; // ~89% response rate
            }
            $this->responses->respond($user, $model->id, [
                'enps' => min(10, $this->rnd->getInt(5, 10) + $better),
                'q1' => min(5, $this->rnd->getInt(2, 5) + $better),
                'q2' => $this->rnd->getInt(2, 5),
            ], $starts->copy()->addDays($i % 6));
            $this->count('survey_responses');
        }
        $this->waves->close(SurveyWave::query()->findOrFail($model->id), $this->now);
        $this->count('survey_waves');
    }

    private function mood(): void
    {
        $rows = [];
        foreach ($this->employees() as $i => $e) {
            if (! $e['active'] || $i % 3 === 2) {
                continue;
            }
            for ($d = 84; $d >= 1; $d--) {
                $day = $this->now->copy()->subDays($d);
                if ($day->isWeekend() || $this->rnd->getInt(0, 9) < 4) {
                    continue;
                }
                $dip = $d > 35 && $d < 50 ? 1 : 0;
                $rows[] = ['employee_id' => $e['id'], 'day' => $day->toDateString(), 'score' => max(1, $this->rnd->getInt(3, 5) - $dip - ($i % 7 === 0 ? 1 : 0)),
                    'comment' => null, 'created_at' => $day, 'updated_at' => $day];
            }
        }
        $this->bulk('mood_checkins', $rows); // deleted with the demo employees
    }

    // ---------------------------------------------------------------- desk, knowledge, assets, hiring

    private function desk(): void
    {
        $admin = $this->admin()->id;
        $employees = $this->employees();
        $categories = [];
        foreach ([['Довідки та документи', 4, 24], ['Зарплата', 8, 48], ['ІТ та доступи', 2, 16]] as [$name, $first, $resolve]) {
            $categories[] = [$this->insert('desk_categories', [
                'name' => self::PREFIX.$name, 'first_response_hours' => $first, 'resolve_hours' => $resolve,
                'default_assignee_id' => $admin, 'active' => true, 'created_at' => $this->now, 'updated_at' => $this->now,
            ]), $first, $resolve];
        }
        $rows = [];
        for ($i = 0; $i < 45; $i++) {
            [$category, $first, $resolve] = $categories[$i % 3];
            $e = $employees[($i * 7) % count($employees)];
            $created = $this->now->copy()->subHours($this->rnd->getInt(2, 90 * 24));
            $firstAt = $created->copy()->addMinutes((int) ($first * 60 * ($i % 4 === 0 ? 1.6 : 0.4)));
            $resolvedAt = $created->copy()->addMinutes((int) ($resolve * 60 * ($i % 6 === 0 ? 1.5 : 0.6)));
            $open = $i % 5 === 1 || $resolvedAt->isFuture();
            $rows[] = [
                'employee_id' => $e['id'], 'category_id' => $category, 'subject' => self::PREFIX.'Звернення №'.($i + 1), 'body' => 'Тестове звернення.',
                'status' => $open ? ($i % 2 === 0 ? 'in_progress' : 'new') : ($i % 3 === 0 ? 'closed' : 'resolved'),
                'assignee_id' => $admin, 'first_response_at' => $firstAt->isFuture() ? null : $firstAt,
                'resolved_at' => $open ? null : $resolvedAt, 'closed_at' => ! $open && $i % 3 === 0 ? $resolvedAt : null,
                'created_by' => $e['user_id'], 'created_at' => $created, 'updated_at' => $created,
            ];
        }
        $this->bulk('desk_cases', $rows);
        $this->registerAll('desk_cases', array_map('intval', DB::table('desk_cases')->whereIn('category_id', array_column($categories, 0))->pluck('id')->all()));
    }

    private function knowledge(): void
    {
        $admin = $this->admin()->id;
        $employees = $this->employees();
        $titles = ['Як оформити відпустку', 'Графік виплат', 'Доступ до пошти', 'Правила відряджень', 'Як отримати довідку', 'Онбординг новачка', 'Лікарняний', 'Техніка та обладнання'];
        $categories = [];
        foreach (['Кадрові питання', 'ІТ'] as $p => $name) {
            $categories[] = $this->insert('kb_categories', ['name' => self::PREFIX.$name, 'emoji' => null, 'position' => 100 + $p, 'created_at' => $this->now, 'updated_at' => $this->now]);
        }
        $votes = [];
        foreach ($titles as $i => $title) {
            $at = $this->now->copy()->subDays(150 - $i * 15);
            $article = $this->insert('kb_articles', [
                'category_id' => $categories[$i % 2], 'title' => self::PREFIX.$title, 'body_md' => 'Тестова стаття.', 'body_html' => '<p>Тестова стаття.</p>',
                'tags' => json_encode(['тест']), 'audience' => json_encode(['type' => 'all']), 'status' => 'published', 'version' => 1,
                'author_id' => $admin, 'updated_by' => $admin, 'published_at' => $at, 'created_at' => $at, 'updated_at' => $at,
            ]);
            foreach ($employees as $v => $e) {
                if (($v + $i) % 4 === 0) {
                    $votes[] = ['article_id' => $article, 'user_id' => $e['user_id'], 'helpful' => ($v + $i) % 5 !== 0, 'created_at' => $at, 'updated_at' => $at];
                }
            }
        }
        $this->bulk('kb_votes', $votes);
    }

    private function assets(): void
    {
        $admin = $this->admin()->id;
        $employees = $this->employees();
        $names = ['Ноутбук', 'Монітор', 'Телефон'];
        $types = [];
        foreach ($names as $name) {
            $types[] = $this->insert('asset_types', ['name' => self::PREFIX.$name, 'created_at' => $this->now, 'updated_at' => $this->now]);
        }
        $rows = [];
        for ($i = 0; $i < 30; $i++) {
            $e = $employees[$i];
            $status = $i < 20 && $e['active'] ? 'assigned' : ['in_stock', 'repair', 'written_off'][$i % 3];
            $bought = $this->now->copy()->subDays($this->rnd->getInt(30, 900));
            $rows[] = [
                'inventory_number' => sprintf('DEMO-%04d', $i + 1), 'serial' => sprintf('SN-DEMO-%05d', $i * 131), 'name' => self::PREFIX.$names[$i % 3],
                'type_id' => $types[$i % 3], 'status' => $status, 'cost' => [32000, 8000, 15000][$i % 3], 'purchased_at' => $bought->toDateString(),
                'notes' => null, 'employee_id' => $status === 'assigned' ? $e['id'] : null, 'created_at' => $bought, 'updated_at' => $bought,
            ];
        }
        $this->bulk('assets', $rows);
        $assets = DB::table('assets')->whereIn('type_id', $types)->get(['id', 'employee_id', 'created_at']);
        $this->registerAll('assets', array_map(static fn (object $a): int => (int) $a->id, $assets->all()));
        $assignments = [];
        foreach ($assets as $a) {
            if ($a->employee_id !== null) {
                $bought = Carbon::parse((string) $a->created_at);
                $assignments[] = ['asset_id' => $a->id, 'employee_id' => $a->employee_id, 'assigned_at' => $bought->copy()->addDays(3), 'condition_out' => 'good',
                    'assigned_by' => $admin, 'created_at' => $bought, 'updated_at' => $bought];
            }
        }
        $this->bulk('asset_assignments', $assignments);
    }

    private function hiring(): void
    {
        $org = $this->org();
        $admin = $this->admin()->id;
        $recruiters = $this->recruiters();
        $steps = DB::table('hiring_route_steps')->orderBy('position')->get()->all();
        $statuses = ['pending', 'approved', 'in_progress', 'rejected', 'closed', 'pending', 'approved'];
        $approvals = [];
        foreach ($statuses as $i => $status) {
            $submitted = $this->now->copy()->subDays(160 - $i * 22);
            $request = $this->insert('hiring_requests', [
                'title' => self::PREFIX.'Заявка на підбір №'.($i + 1), 'branch_id' => $org['branches'][$i % 3], 'department_id' => $org['departments'][$i % 4],
                'position_id' => $org['positions'][2], 'headcount' => 1 + $i % 2, 'reason' => $i % 3 === 0 ? 'replacement' : 'new_position',
                'desired_start_date' => $submitted->copy()->addDays(45)->toDateString(), 'salary_min' => 25000, 'salary_max' => 35000, 'currency' => 'UAH',
                'requirements' => 'Тестові вимоги.', 'priority' => ['normal', 'high', 'low', 'urgent'][$i % 4], 'extra' => '{}', 'status' => $status,
                'requester_id' => $admin, 'recruiter_id' => $recruiters[$i % 3]->id,
                'submitted_at' => $submitted, 'decided_at' => $status === 'pending' ? null : $submitted->copy()->addDays(3),
                'closed_at' => $status === 'closed' ? $submitted->copy()->addDays(40) : null, 'created_at' => $submitted, 'updated_at' => $submitted,
            ]);
            foreach ($steps as $s => $step) {
                $state = match (true) {
                    $status === 'pending' => $s === 0 ? 'pending' : 'waiting',
                    $status === 'rejected' => $s === count($steps) - 1 ? 'rejected' : 'approved',
                    default => 'approved',
                };
                $decided = in_array($state, ['approved', 'rejected'], true);
                $approvals[] = [
                    'hiring_request_id' => $request, 'position' => $step->position, 'name' => $step->name, 'kind' => $step->kind, 'role' => $step->role,
                    'approver_id' => $admin, 'sla_days' => $step->sla_days, 'status' => $state,
                    'activated_at' => $state === 'waiting' ? null : $submitted, 'due_at' => $state === 'waiting' ? null : $submitted->copy()->addDays((int) $step->sla_days),
                    'decided_by' => $decided ? $admin : null, 'decided_at' => $decided ? $submitted->copy()->addDays(1 + $s) : null,
                    'comment' => null, 'notified' => true, 'escalated' => false, 'created_at' => $submitted, 'updated_at' => $submitted,
                ];
            }
        }
        $this->bulk('hiring_request_approvals', $approvals);
    }

    // ---------------------------------------------------------------- helpers

    private function marker(string $step): string
    {
        return DemoRegistry::STEP_PREFIX.$step;
    }

    /** @param  array<string, mixed>  $row */
    private function insert(string $table, array $row): int
    {
        $id = (int) DB::table($table)->insertGetId($row);
        $this->registry->add($table, $id);
        $this->count($table);

        return $id;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function bulk(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table($table)->insert($chunk);
        }
        $this->counts[$table] = ($this->counts[$table] ?? 0) + count($rows);
    }

    /** @param  list<int>  $ids */
    private function registerAll(string $table, array $ids): void
    {
        foreach ($ids as $id) {
            $this->registry->add($table, $id);
        }
    }

    private function count(string $table): void
    {
        $this->counts[$table] = ($this->counts[$table] ?? 0) + 1;
    }
}
