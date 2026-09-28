<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Demo;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Pulse\Models\SurveyWave;
use App\Modules\Pulse\Services\ResponseService;
use App\Modules\Pulse\Services\WaveLifecycle;
use App\Modules\Recruiting\DTO\VacancyData;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Services\RecruitingDemoData;
use App\Modules\Recruiting\Services\VacancyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Company-wide synthetic data for the report charts (POST /api/ops/demo-fill?confirm=demo): structure, ~60 people with
 * pay, recruiting funnel with channel costs and touches, time off, timesheets, OKR, 1:1s, a closed 360 cycle, two closed
 * Pulse waves, mood, Desk, knowledge, assets, hiring requests, script scores — the last 6 months.
 *
 * Rules: every name/title starts with "[ТЕСТ]", e-mails are on the reserved example.test domain; every created root
 * row is listed in demo_records (DemoRegistry), so a re-run is a no-op and reset deletes only demo rows. Randomness is
 * a seeded Mt19937 (Faker is a dev dependency, absent on deploys). Recruiting and Pulse go through their own services
 * (stage history, captured touches, anonymous responses, membership snapshot on close); the rest are plain inserts.
 */
final class DemoDataService
{
    public const string PREFIX = '[ТЕСТ] ';

    private const int SEED = 20260928;

    private const array FIRST_F = ['Олена', 'Марія', 'Ірина', 'Наталія', 'Юлія', 'Тетяна', 'Софія', 'Катерина', 'Анна', 'Вікторія', 'Оксана', 'Дарина'];

    private const array FIRST_M = ['Андрій', 'Дмитро', 'Олег', 'Сергій', 'Максим', 'Богдан', 'Віктор', 'Роман', 'Ігор', 'Павло', 'Тарас', 'Євген'];

    private const array LAST = ['Коваленко', 'Бондаренко', 'Ткаченко', 'Кравченко', 'Олійник', 'Шевчук', 'Поліщук', 'Савченко', 'Руденко', 'Марченко', 'Мороз', 'Лисенко', 'Гончаренко', 'Павленко'];

    private const array DEPARTMENTS = ['Продажі', 'Навчання', 'Адміністрація', 'Маркетинг'];

    /** position => [base monthly pay UAH, level] */
    private const array POSITIONS = ['Керівник відділу' => 62000, 'Старший спеціаліст' => 42000, 'Спеціаліст' => 30000, 'Молодший спеціаліст' => 22000];

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
    ) {}

    public function filled(): bool
    {
        return $this->registry->filled();
    }

    /** @return array{already: bool, counts: array<string, int>} */
    public function fill(): array
    {
        if ($this->registry->filled()) {
            return ['already' => true, 'counts' => []];
        }
        $this->rnd = new Randomizer(new Mt19937(self::SEED));
        $this->now = Carbon::now()->startOfMinute();
        $this->counts = [];
        DB::transaction(function (): void {
            $org = $this->structure();
            $people = $this->people($org);
            $this->registry->flush();
            $this->recruitingData($org, $people);
            $this->timeOff($people);
            $this->timesheets($people);
            $this->perform($people, $org);
            $this->pulse($people);
            $this->mood($people);
            $this->desk($people);
            $this->knowledge($people);
            $this->assets($people);
            $this->hiring($org, $people);
            $this->registry->flush();
        });

        return ['already' => false, 'counts' => $this->counts];
    }

    /** @return array<string, int> */
    public function reset(): array
    {
        return DB::transaction(fn (): array => $this->registry->purge());
    }

    // ---------------------------------------------------------------- structure & people

    /** @return array{branches: list<int>, departments: list<int>, positions: array<string, int>} */
    private function structure(): array
    {
        $cities = [];
        foreach (['Київ', 'Львів', 'Дніпро'] as $name) {
            $cities[] = $this->insert('cities', ['name' => self::PREFIX.$name, 'status' => 'active']);
        }
        $branches = [];
        foreach (['Київ Центр', 'Львів', 'Дніпро'] as $i => $name) {
            $branches[] = $this->insert('branches', ['name' => self::PREFIX.$name, 'status' => 'active', 'city_id' => $cities[$i]]);
        }
        $departments = [];
        foreach (self::DEPARTMENTS as $name) {
            $departments[] = $this->insert('departments', ['name' => self::PREFIX.$name, 'status' => 'active']);
        }
        $positions = [];
        foreach (array_keys(self::POSITIONS) as $name) {
            $positions[$name] = $this->insert('positions', ['name' => self::PREFIX.$name, 'status' => 'active']);
        }

        return ['branches' => $branches, 'departments' => $departments, 'positions' => $positions];
    }

    /**
     * 3 branches × 4 departments × 5 people; the first of each cell is its head (reports to the director = #0),
     * genders alternate so every department has both genders ≥ 5. Six people left during the last months.
     *
     * @param  array{branches: list<int>, departments: list<int>, positions: array<string, int>}  $org
     * @return array{admin: User, recruiters: list<User>, employees: list<array{id: int, user: User, branch: int, department: int, manager: int|null, active: bool}>}
     */
    private function people(array $org): array
    {
        $admin = $this->user('demo-admin@example.test', self::PREFIX.'Адміністратор', UserRole::Admin, $org['branches']);
        $recruiters = [];
        foreach ([1, 2, 3] as $n) {
            $recruiters[] = $this->user("demo-hr-{$n}@example.test", self::PREFIX.'Рекрутер '.$n, UserRole::Recruiter, [$org['branches'][$n - 1]]);
        }
        $positionNames = array_keys(self::POSITIONS);
        $employees = [];
        $n = 0;
        $director = null;
        foreach ($org['branches'] as $b => $branch) {
            foreach ($org['departments'] as $d => $department) {
                $head = null;
                for ($k = 0; $k < 5; $k++, $n++) {
                    $female = ($n + $d) % 2 === 0;
                    $name = self::PREFIX.self::LAST[$n % count(self::LAST)].' '.($female ? self::FIRST_F : self::FIRST_M)[($n * 5) % 12];
                    $user = $this->user(sprintf('demo-emp-%02d@example.test', $n + 1), $name, UserRole::Employee, [$branch]);
                    $level = $k === 0 ? 0 : min(3, 1 + $this->rnd->getInt(0, 2));
                    $terminated = $n % 10 === 7;
                    $hired = $this->now->copy()->subDays($n % 6 === 1 ? $this->rnd->getInt(10, 175) : $this->rnd->getInt(200, 1500));
                    $manager = $k === 0 ? $director : $head;
                    $id = $this->insert('employees', [
                        'user_id' => $user->id, 'full_name' => $name, 'work_email' => $user->email,
                        'phone' => sprintf('+38050%07d', 3000000 + $n * 7727),
                        'birth_date' => $this->now->copy()->subYears(22 + $this->rnd->getInt(0, 30))->subDays($this->rnd->getInt(0, 364))->toDateString(),
                        'hired_at' => $hired->toDateString(),
                        'fired_at' => $terminated ? $this->now->copy()->subDays($this->rnd->getInt(5, 170))->toDateString() : null,
                        'termination_reason' => $terminated ? 'Власне бажання' : null,
                        'status' => $terminated ? 'terminated' : ($n % 17 === 4 ? 'on_leave' : 'active'),
                        'employment_type' => $n % 9 === 5 ? 'part_time' : 'full_time',
                        'branch_id' => $branch, 'department_id' => $department,
                        'position_id' => $org['positions'][$positionNames[$level]], 'manager_id' => $manager,
                        'gender' => $female ? 'female' : 'male',
                        'created_at' => $hired, 'updated_at' => $this->now,
                    ]);
                    $director ??= $id;
                    $head ??= $id;
                    $employees[] = ['id' => $id, 'user' => $user, 'branch' => $branch, 'department' => $department, 'manager' => $manager, 'active' => ! $terminated];
                    $base = self::POSITIONS[$positionNames[$level]] * ($female ? 0.93 : 1.0) * (0.9 + $this->rnd->getInt(0, 20) / 100);
                    $this->insert('employee_compensations', [
                        'employee_id' => $id, 'amount' => round($base * 0.9, -2), 'currency' => 'UAH', 'period' => 'month',
                        'effective_on' => $hired->toDateString(), 'reason' => 'Прийом', 'created_at' => $hired, 'updated_at' => $hired,
                    ]);
                    if ($n % 3 === 0) {
                        $raise = $this->now->copy()->subDays($this->rnd->getInt(10, 150));
                        $this->insert('employee_compensations', [
                            'employee_id' => $id, 'amount' => round($base, -2), 'currency' => 'UAH', 'period' => 'month',
                            'effective_on' => $raise->toDateString(), 'reason' => 'Перегляд', 'created_at' => $raise, 'updated_at' => $raise,
                        ]);
                    }
                }
            }
        }

        return ['admin' => $admin, 'recruiters' => $recruiters, 'employees' => $employees];
    }

    /** @param  list<int>  $branches */
    private function user(string $email, string $name, UserRole $role, array $branches): User
    {
        $user = User::query()->create(['email' => $email, 'name' => $name, 'status' => 'active', 'approval_emails' => false]);
        $this->registry->add('users', $user->id);
        $this->count('users');
        $user->syncRoles([$role->value]);
        $user->branches()->sync($branches);

        return $user;
    }

    // ---------------------------------------------------------------- recruiting

    /**
     * @param  array{branches: list<int>, departments: list<int>, positions: array<string, int>}  $org
     * @param  array{admin: User, recruiters: list<User>, employees: list<array<string, mixed>>}  $people
     */
    private function recruitingData(array $org, array $people): void
    {
        $titles = ['Менеджер з продажу', 'Викладач англійської', 'Адміністратор філії', 'SMM-менеджер', 'Координатор навчання', 'Бухгалтер', 'Менеджер з продажу (вечірня зміна)', 'Методист'];
        $vacancies = [];
        foreach ($titles as $i => $title) {
            $vacancy = $this->vacancies->create($people['admin'], new VacancyData([
                'title' => self::PREFIX.$title,
                'branch_id' => $org['branches'][$i % 3],
                'department_id' => $org['departments'][$i % 4],
                'position_id' => array_values($org['positions'])[2 + $i % 2],
                'recruiter_id' => $people['recruiters'][$i % 3]->id,
                'status' => $i === 7 ? 'paused' : 'open',
                'description' => 'Тестова вакансія (синтетичні дані).',
            ]));
            $this->registry->add('vacancies', $vacancy->id);
            $vacancy->forceFill(['created_at' => $this->now->copy()->subDays(185 - $i * 12)])->saveQuietly();
            $vacancies[] = $vacancy;
        }
        $this->counts['vacancies'] = count($vacancies);

        /** @var list<int> $channels */
        $channels = array_map('intval', DB::table('acquisition_channels')->where('active', true)->orderBy('id')->limit(6)->pluck('id')->all());
        foreach ($channels as $c => $channel) {
            for ($m = 5; $m >= 0; $m--) {
                $month = $this->now->copy()->startOfMonth()->subMonths($m);
                $this->insert('acquisition_channel_costs', [
                    'channel_id' => $channel, 'period_start' => $month->toDateString(), 'period_end' => $month->copy()->endOfMonth()->toDateString(),
                    'amount' => 2000 + 1500 * $c + $this->rnd->getInt(0, 40) * 100, 'currency' => 'UAH', 'note' => self::PREFIX.'витрати',
                    'created_at' => $month, 'updated_at' => $month,
                ]);
            }
        }

        $stories = $this->recruiting->populate($people['recruiters'], $vacancies, 150, 180, self::PREFIX, $channels);
        $touches = 0;
        $candidates = [];
        foreach ($stories as $story) {
            $this->registry->add('candidates', $story['candidate']);
            $touches += $story['touches'];
            $candidates[] = $story['candidate'];
        }
        $models = Candidate::query()->whereIn('id', $candidates)->orderBy('id')->get()->all();
        for ($t = 0; $touches < 600 && $models !== []; $t++, $touches++) {
            $at = $this->now->copy()->subMinutes($this->rnd->getInt(60, 90 * 24 * 60));
            $this->recruiting->extraTouch($models[$t % count($models)], $people['recruiters'][$t % 3], $t, $at);
        }
        $this->counts['candidates'] = count($candidates);
        $this->counts['touchpoints'] = $touches;
        $this->scripts($candidates);
    }

    /** @param  list<int>  $candidates */
    private function scripts(array $candidates): void
    {
        $script = $this->insert('scripts', ['name' => self::PREFIX.'Перший дзвінок кандидату', 'channel' => 'call', 'archived' => false, 'created_at' => $this->now, 'updated_at' => $this->now]);
        $version = $this->insert('script_versions', [
            'script_id' => $script, 'version' => 1, 'published_at' => $this->now->copy()->subDays(120),
            'steps' => json_encode([['id' => 's1', 'title' => 'Привітання'], ['id' => 's2', 'title' => 'Умови'], ['id' => 's3', 'title' => 'Наступний крок']]),
            'objections' => '[]', 'templates' => '[]', 'followups' => '[]', 'next_step_patterns' => '{}',
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
        DB::table('scripts')->where('id', $script)->update(['active_version_id' => $version]);
        $calls = DB::table('touchpoints')->whereIn('candidate_id', $candidates)->where('channel', 'call')->orderBy('id')->limit(80)->pluck('id')->all();
        foreach ($calls as $i => $touchpoint) {
            $score = $this->rnd->getInt(45, 100);
            DB::table('script_evaluations')->insert([
                'touchpoint_id' => $touchpoint, 'script_version_id' => $version, 'engine' => 'rules', 'score' => $score,
                'result' => json_encode(['next_step' => ['fixed' => $score > 65 || $i % 4 === 0]]), 'created_at' => $this->now,
            ]);
        }
        $this->counts['script_evaluations'] = count($calls);
    }

    // ---------------------------------------------------------------- time off & time

    /** @param  array{employees: list<array{id: int, user: User, branch: int, department: int, manager: int|null, active: bool}>, admin: User}  $people */
    private function timeOff(array $people): void
    {
        /** @var list<int> $types */
        $types = array_map('intval', DB::table('leave_types')->where('active', true)->orderBy('id')->pluck('id')->all());
        if ($types === []) {
            return;
        }
        foreach ($people['employees'] as $i => $e) {
            $requests = $i % 3 === 0 ? 2 : 1;
            for ($r = 0; $r < $requests; $r++) {
                $current = $i % 8 === 2 && $r === 0;
                $start = $current ? $this->now->copy()->subDays($this->rnd->getInt(0, 3)) : $this->now->copy()->subDays($this->rnd->getInt(-20, 175));
                $days = $this->rnd->getInt(1, 10);
                $status = $current ? 'approved' : ['approved', 'approved', 'approved', 'pending', 'rejected'][($i + $r) % 5];
                $this->insert('leave_requests', [
                    'employee_id' => $e['id'], 'leave_type_id' => $types[($i + $r) % count($types)],
                    'starts_on' => $start->toDateString(), 'ends_on' => $start->copy()->addDays($days - 1)->toDateString(),
                    'half_day' => 'none', 'days' => $days, 'comment' => self::PREFIX.'відпустка', 'status' => $status,
                    'balance_override' => false, 'approver_id' => $status === 'pending' ? null : $people['admin']->id,
                    'decided_at' => $status === 'pending' ? null : $start->copy()->subDays(7),
                    'created_by' => $e['user']->id, 'created_at' => $start->copy()->subDays(10), 'updated_at' => $start->copy()->subDays(7),
                ]);
            }
        }
    }

    /** @param  array{employees: list<array{id: int, user: User, branch: int, department: int, manager: int|null, active: bool}>, admin: User}  $people */
    private function timesheets(array $people): void
    {
        $entries = [];
        $projects = ['Набір студентів', 'Навчальний процес', 'Внутрішні задачі', 'Маркетинг'];
        foreach (array_slice($people['employees'], 0, 45) as $i => $e) {
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
                $sheet = $this->insert('timesheets', [
                    'employee_id' => $e['id'], 'week_start' => $week->toDateString(),
                    'status' => $w === 1 ? 'submitted' : 'approved', 'expected_hours' => 40, 'worked_hours' => $worked,
                    'overtime_hours' => max(0, $worked - 40), 'submitted_at' => $week->copy()->addDays(4)->setTime(18, 0),
                    'decided_by' => $w === 1 ? null : $people['admin']->id, 'decided_at' => $w === 1 ? null : $week->copy()->addDays(7),
                    'created_at' => $week, 'updated_at' => $week->copy()->addDays(4),
                ]);
                foreach ($hours as $d => $h) {
                    $entries[] = [
                        'timesheet_id' => $sheet, 'date' => $week->copy()->addDays($d)->toDateString(), 'hours' => $h,
                        'project' => $projects[($i + $d) % 4], 'category' => 'work', 'note' => null, 'created_at' => $week, 'updated_at' => $week,
                    ];
                }
            }
        }
        foreach (array_chunk($entries, 300) as $chunk) {
            DB::table('time_entries')->insert($chunk);
        }
    }

    // ---------------------------------------------------------------- performance

    /**
     * @param  array{employees: list<array{id: int, user: User, branch: int, department: int, manager: int|null, active: bool}>, admin: User}  $people
     * @param  array{branches: list<int>, departments: list<int>, positions: array<string, int>}  $org
     */
    private function perform(array $people, array $org): void
    {
        $active = array_values(array_filter($people['employees'], static fn (array $e): bool => $e['active']));
        $quarter = $this->now->year.'-Q'.$this->now->quarter;
        $objectives = [];
        foreach ($org['departments'] as $d => $department) {
            $objectives[] = [$this->objective('team', null, $department, $quarter, self::DEPARTMENTS[$d].': ключова ціль кварталу', $people['admin']), 'team'];
        }
        $objectives[] = [$this->objective('company', null, null, $quarter, 'Зростання набору на 20%', $people['admin']), 'company'];
        foreach (array_slice($active, 0, 16) as $e) {
            $objectives[] = [$this->objective('personal', $e['id'], $e['department'], $quarter, 'Особиста ціль розвитку', $e['user']), 'personal'];
        }
        foreach ($objectives as $o => [$id]) {
            $progress = 0;
            for ($c = 1; $c <= 3; $c++) {
                $after = min(100, $progress + $this->rnd->getInt(5, 35));
                $at = $this->now->copy()->subDays(70 - $c * 20 - $o % 5);
                $this->insert('objective_checkins', [
                    'objective_id' => $id, 'author_id' => $people['admin']->id, 'progress_before' => $progress, 'progress_after' => $after,
                    'key_results' => json_encode([['id' => 'kr1', 'current' => $after]]), 'comment' => 'Оновлення прогресу',
                    'created_at' => $at, 'updated_at' => $at,
                ]);
                $progress = $after;
            }
            DB::table('objectives')->where('id', $id)->update([
                'progress' => $progress,
                'key_results' => json_encode([['id' => 'kr1', 'title' => 'Ключовий результат', 'start' => 0, 'target' => 100, 'current' => $progress, 'unit' => '%', 'weight' => 1]]),
            ]);
        }

        // 1:1s: every head with their people, the last 3 months, next ones scheduled.
        foreach ($active as $i => $e) {
            if ($e['manager'] === null || $i % 2 === 1) {
                continue;
            }
            foreach ([80, 45, 10, -7] as $days) {
                $at = $this->now->copy()->subDays($days + $i % 5)->setTime(11, 0);
                $this->insert('one_on_ones', [
                    'manager_employee_id' => $e['manager'], 'employee_id' => $e['id'], 'scheduled_at' => $at,
                    'agenda' => json_encode([['id' => 'a1', 'text' => 'Як справи з задачами?', 'done' => $days > 0]]),
                    'notes_shared' => $days > 0 ? 'Обговорили пріоритети.' : null, 'action_items' => '[]',
                    'status' => $days > 0 ? ($i % 9 === 4 ? 'cancelled' : 'completed') : 'scheduled',
                    'created_by' => $people['admin']->id, 'created_at' => $at->copy()->subDays(7), 'updated_at' => $at,
                ]);
            }
        }
        $this->review($active, $people['admin']);
    }

    private function objective(string $scope, ?int $owner, ?int $department, string $period, string $title, User $by): int
    {
        $at = $this->now->copy()->startOfQuarter();

        return $this->insert('objectives', [
            'scope' => $scope, 'owner_employee_id' => $owner, 'department_id' => $scope === 'team' ? $department : null, 'branch_id' => null,
            'period' => $period, 'title' => self::PREFIX.$title, 'description' => null, 'key_results' => '[]', 'progress' => 0,
            'status' => 'active', 'visibility' => 'public', 'created_by' => $by->id, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    /** @param  list<array{id: int, user: User, branch: int, department: int, manager: int|null, active: bool}>  $active */
    private function review(array $active, User $admin): void
    {
        $scale = $this->insert('rating_scales', ['name' => self::PREFIX.'Шкала 1–5', 'levels' => json_encode(array_map(
            static fn (int $v): array => ['value' => $v, 'label' => (string) $v], [1, 2, 3, 4, 5],
        )), 'created_at' => $this->now, 'updated_at' => $this->now]);
        $competencies = [];
        foreach (['Комунікація', 'Відповідальність', 'Командна робота', 'Орієнтація на результат'] as $name) {
            $competencies[] = $this->insert('competencies', ['name' => self::PREFIX.$name, 'scale_id' => $scale, 'active' => true, 'created_at' => $this->now, 'updated_at' => $this->now]);
        }
        $start = $this->now->copy()->subMonths(4)->startOfQuarter();
        $cycle = $this->insert('review_cycles', [
            'name' => self::PREFIX.'Оцінка 360 '.$start->year.'-Q'.$start->quarter, 'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfQuarter()->toDateString(), 'participants' => json_encode(['branch_ids' => [], 'department_ids' => []]),
            'types' => json_encode(['self', 'manager', 'peer']), 'competency_ids' => json_encode($competencies), 'anonymous' => true,
            'deadlines' => '{}', 'status' => 'closed', 'created_by' => $admin->id,
            'activated_at' => $start->copy()->addMonths(3), 'closed_at' => $start->copy()->addMonths(3)->addDays(14),
            'created_at' => $start, 'updated_at' => $start->copy()->addMonths(3)->addDays(14),
        ]);
        $submitted = $start->copy()->addMonths(3)->addDays(7);
        foreach (array_slice($active, 0, 24) as $i => $subject) {
            $peers = [$active[($i + 1) % count($active)]['id'], $active[($i + 2) % count($active)]['id']];
            $raters = [['self', $subject['id']], ['manager', $subject['manager'] ?? $active[0]['id']], ['peer', $peers[0]], ['peer', $peers[1]]];
            foreach ($raters as [$type, $reviewer]) {
                $assignment = $this->insert('review_assignments', [
                    'cycle_id' => $cycle, 'subject_employee_id' => $subject['id'], 'reviewer_employee_id' => $reviewer, 'type' => $type,
                    'status' => 'submitted', 'submitted_at' => $submitted, 'created_at' => $start, 'updated_at' => $submitted,
                ]);
                foreach ($competencies as $competency) {
                    DB::table('review_answers')->insert([
                        'assignment_id' => $assignment, 'competency_id' => $competency, 'rating' => $this->rnd->getInt(2, 5),
                        'comment' => null, 'created_at' => $submitted, 'updated_at' => $submitted,
                    ]);
                }
            }
        }
    }

    // ---------------------------------------------------------------- pulse & mood

    /** @param  array{employees: list<array{id: int, user: User, branch: int, department: int, manager: int|null, active: bool}>, admin: User}  $people */
    private function pulse(array $people): void
    {
        $survey = $this->insert('surveys', [
            'title' => self::PREFIX.'Пульс залученості', 'type' => 'engagement', 'description' => 'Тестове опитування.',
            'questions' => json_encode([
                ['id' => 'enps', 'type' => 'enps', 'text' => 'Наскільки ймовірно, що ви порекомендуєте нас як роботодавця?', 'required' => true],
                ['id' => 'q1', 'type' => 'scale5', 'text' => 'Я розумію, чого від мене очікують', 'required' => true],
                ['id' => 'q2', 'type' => 'scale5', 'text' => 'Я маю все необхідне для роботи', 'required' => true],
            ]),
            'active' => true, 'created_by' => $people['admin']->id, 'created_at' => $this->now->copy()->subDays(120), 'updated_at' => $this->now,
        ]);
        $responders = array_values(array_filter($people['employees'], static fn (array $e): bool => $e['active']));
        foreach ([[100, 0], [40, 1]] as [$ago, $wave]) {
            $starts = $this->now->copy()->subDays($ago);
            $model = $this->waves->create([
                'survey_id' => $survey, 'schedule' => 'once', 'audience' => ['branch_ids' => [], 'department_ids' => []],
                'anonymous' => true, 'min_group_size' => 5, 'starts_at' => $starts, 'ends_at' => $starts->copy()->addDays(7),
                'created_by' => $people['admin']->id,
            ], $this->now);
            $this->registry->add('survey_waves', $model->id);
            foreach ($responders as $i => $e) {
                if (($i + $wave) % 9 === 0) {
                    continue; // ~89% response rate
                }
                $mood = $wave === 1 ? 1 : 0; // the second wave is a bit better
                $this->responses->respond($e['user'], $model->id, [
                    'enps' => min(10, $this->rnd->getInt(5, 10) + $mood),
                    'q1' => min(5, $this->rnd->getInt(2, 5) + $mood),
                    'q2' => $this->rnd->getInt(2, 5),
                ], $starts->copy()->addDays($i % 6));
            }
            $fresh = SurveyWave::query()->findOrFail($model->id);
            $this->waves->close($fresh, $this->now);
            $this->count('survey_waves');
        }
    }

    /** @param  array{employees: list<array{id: int, user: User, branch: int, department: int, manager: int|null, active: bool}>, admin: User}  $people */
    private function mood(array $people): void
    {
        $rows = [];
        foreach ($people['employees'] as $i => $e) {
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
        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('mood_checkins')->insert($chunk);
        }
        $this->counts['mood_checkins'] = count($rows);
    }

    // ---------------------------------------------------------------- desk, knowledge, assets, hiring

    /** @param  array{employees: list<array{id: int, user: User, branch: int, department: int, manager: int|null, active: bool}>, admin: User}  $people */
    private function desk(array $people): void
    {
        $categories = [];
        foreach ([['Довідки та документи', 4, 24], ['Зарплата', 8, 48], ['ІТ та доступи', 2, 16]] as [$name, $first, $resolve]) {
            $categories[] = [$this->insert('desk_categories', [
                'name' => self::PREFIX.$name, 'first_response_hours' => $first, 'resolve_hours' => $resolve,
                'default_assignee_id' => $people['admin']->id, 'active' => true, 'created_at' => $this->now, 'updated_at' => $this->now,
            ]), $first, $resolve];
        }
        for ($i = 0; $i < 45; $i++) {
            [$category, $first, $resolve] = $categories[$i % 3];
            $e = $people['employees'][($i * 7) % count($people['employees'])];
            $created = $this->now->copy()->subHours($this->rnd->getInt(2, 90 * 24));
            $late = $i % 4 === 0;
            $firstAt = $created->copy()->addMinutes((int) ($first * 60 * ($late ? 1.6 : 0.4)));
            $done = $i % 5 !== 1;
            $resolvedAt = $created->copy()->addMinutes((int) ($resolve * 60 * ($i % 6 === 0 ? 1.5 : 0.6)));
            $open = ! $done || $resolvedAt->isFuture();
            $this->insert('desk_cases', [
                'employee_id' => $e['id'], 'category_id' => $category, 'subject' => self::PREFIX.'Звернення №'.($i + 1), 'body' => 'Тестове звернення.',
                'status' => $open ? ($i % 2 === 0 ? 'in_progress' : 'new') : ($i % 3 === 0 ? 'closed' : 'resolved'),
                'assignee_id' => $people['admin']->id, 'first_response_at' => $firstAt->isFuture() ? null : $firstAt,
                'resolved_at' => $open ? null : $resolvedAt, 'closed_at' => ! $open && $i % 3 === 0 ? $resolvedAt : null,
                'created_by' => $e['user']->id, 'created_at' => $created, 'updated_at' => $created,
            ]);
        }
    }

    /** @param  array{employees: list<array{id: int, user: User, branch: int, department: int, manager: int|null, active: bool}>, admin: User}  $people */
    private function knowledge(array $people): void
    {
        $titles = ['Як оформити відпустку', 'Графік виплат', 'Доступ до пошти', 'Правила відряджень', 'Як отримати довідку', 'Онбординг новачка', 'Лікарняний', 'Техніка та обладнання'];
        $categories = [];
        foreach (['Кадрові питання', 'ІТ'] as $p => $name) {
            $categories[] = $this->insert('kb_categories', ['name' => self::PREFIX.$name, 'emoji' => null, 'position' => 100 + $p, 'created_at' => $this->now, 'updated_at' => $this->now]);
        }
        foreach ($titles as $i => $title) {
            $at = $this->now->copy()->subDays(150 - $i * 15);
            $article = $this->insert('kb_articles', [
                'category_id' => $categories[$i % 2], 'title' => self::PREFIX.$title, 'body_md' => 'Тестова стаття.', 'body_html' => '<p>Тестова стаття.</p>',
                'tags' => json_encode(['тест']), 'audience' => json_encode(['type' => 'all']), 'status' => 'published', 'version' => 1,
                'author_id' => $people['admin']->id, 'updated_by' => $people['admin']->id, 'published_at' => $at, 'created_at' => $at, 'updated_at' => $at,
            ]);
            foreach ($people['employees'] as $v => $e) {
                if (($v + $i) % 4 !== 0) {
                    continue;
                }
                DB::table('kb_votes')->insert(['article_id' => $article, 'user_id' => $e['user']->id, 'helpful' => ($v + $i) % 5 !== 0, 'created_at' => $at, 'updated_at' => $at]);
            }
        }
    }

    /** @param  array{employees: list<array{id: int, user: User, branch: int, department: int, manager: int|null, active: bool}>, admin: User}  $people */
    private function assets(array $people): void
    {
        $types = [];
        foreach (['Ноутбук', 'Монітор', 'Телефон'] as $name) {
            $types[] = $this->insert('asset_types', ['name' => self::PREFIX.$name, 'created_at' => $this->now, 'updated_at' => $this->now]);
        }
        for ($i = 0; $i < 30; $i++) {
            $e = $people['employees'][$i];
            $status = $i < 20 && $e['active'] ? 'assigned' : ['in_stock', 'repair', 'written_off'][$i % 3];
            $bought = $this->now->copy()->subDays($this->rnd->getInt(30, 900));
            $asset = $this->insert('assets', [
                'inventory_number' => sprintf('DEMO-%04d', $i + 1), 'serial' => sprintf('SN-DEMO-%05d', $i * 131), 'name' => self::PREFIX.['Ноутбук', 'Монітор', 'Телефон'][$i % 3],
                'type_id' => $types[$i % 3], 'status' => $status, 'cost' => [32000, 8000, 15000][$i % 3], 'purchased_at' => $bought->toDateString(),
                'notes' => null, 'employee_id' => $status === 'assigned' ? $e['id'] : null, 'created_at' => $bought, 'updated_at' => $bought,
            ]);
            if ($status === 'assigned') {
                DB::table('asset_assignments')->insert([
                    'asset_id' => $asset, 'employee_id' => $e['id'], 'assigned_at' => $bought->copy()->addDays(3), 'condition_out' => 'good',
                    'assigned_by' => $people['admin']->id, 'created_at' => $bought, 'updated_at' => $bought,
                ]);
            }
        }
    }

    /**
     * @param  array{branches: list<int>, departments: list<int>, positions: array<string, int>}  $org
     * @param  array{employees: list<array<string, mixed>>, admin: User, recruiters: list<User>}  $people
     */
    private function hiring(array $org, array $people): void
    {
        $steps = DB::table('hiring_route_steps')->orderBy('position')->get()->all();
        $statuses = ['pending', 'approved', 'in_progress', 'rejected', 'closed', 'pending', 'approved'];
        foreach ($statuses as $i => $status) {
            $submitted = $this->now->copy()->subDays(160 - $i * 22);
            $request = $this->insert('hiring_requests', [
                'title' => self::PREFIX.'Заявка на підбір №'.($i + 1), 'branch_id' => $org['branches'][$i % 3], 'department_id' => $org['departments'][$i % 4],
                'position_id' => array_values($org['positions'])[2], 'headcount' => 1 + $i % 2, 'reason' => $i % 3 === 0 ? 'replacement' : 'new_position',
                'desired_start_date' => $submitted->copy()->addDays(45)->toDateString(), 'salary_min' => 25000, 'salary_max' => 35000, 'currency' => 'UAH',
                'requirements' => 'Тестові вимоги.', 'priority' => ['normal', 'high', 'low', 'urgent'][$i % 4], 'extra' => '{}', 'status' => $status,
                'requester_id' => $people['admin']->id, 'recruiter_id' => $people['recruiters'][$i % 3]->id,
                'submitted_at' => $submitted, 'decided_at' => $status === 'pending' ? null : $submitted->copy()->addDays(3),
                'closed_at' => $status === 'closed' ? $submitted->copy()->addDays(40) : null, 'created_at' => $submitted, 'updated_at' => $submitted,
            ]);
            foreach ($steps as $s => $step) {
                $state = match (true) {
                    $status === 'pending' => $s === 0 ? 'pending' : 'waiting',
                    $status === 'rejected' => $s === count($steps) - 1 ? 'rejected' : 'approved',
                    default => 'approved',
                };
                DB::table('hiring_request_approvals')->insert([
                    'hiring_request_id' => $request, 'position' => $step->position, 'name' => $step->name, 'kind' => $step->kind, 'role' => $step->role,
                    'approver_id' => $people['admin']->id, 'sla_days' => $step->sla_days, 'status' => $state,
                    'activated_at' => $state === 'waiting' ? null : $submitted, 'due_at' => $state === 'waiting' ? null : $submitted->copy()->addDays((int) $step->sla_days),
                    'decided_by' => in_array($state, ['approved', 'rejected'], true) ? $people['admin']->id : null,
                    'decided_at' => in_array($state, ['approved', 'rejected'], true) ? $submitted->copy()->addDays(1 + $s) : null,
                    'comment' => null, 'notified' => true, 'escalated' => false, 'created_at' => $submitted, 'updated_at' => $submitted,
                ]);
            }
        }
        $this->counts['hiring_requests'] = count($statuses);
    }

    // ---------------------------------------------------------------- helpers

    /** @param  array<string, mixed>  $row */
    private function insert(string $table, array $row): int
    {
        $id = (int) DB::table($table)->insertGetId($row);
        $this->registry->add($table, $id);
        $this->count($table);

        return $id;
    }

    private function count(string $table): void
    {
        $this->counts[$table] = ($this->counts[$table] ?? 0) + 1;
    }
}
