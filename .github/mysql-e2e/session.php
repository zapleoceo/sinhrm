<?php

// CI-only helper of the MySQL 8.4 e2e run (.github/workflows/mysql-e2e.yml). Runs against the disposable CI database:
// gives the demo admin superadmin + hr_manager, adds synthetic people with tricky names (case / ё-е / ї-і),
// and prints session cookies (JSON) of the demo admin and of one demo user per role: recruiter, manager (an employee
// user with direct reports), employee (a rank-and-file user whose manager is that manager) — docs/guides/ui-parity.md,
// «Перезапис фікстур». Never a route, never committed to main.

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Support\CredentialSession;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$admin = User::query()->where('email', 'demo+admin@sinhrm.test')->firstOrFail();
$admin->assignRole('superadmin', 'hr_manager');
$admin->forceFill(['status' => 'active', 'safe_speak_handler' => true])->save();

// Tricky names for the search checks (a copy of an existing demo employee: every NOT NULL column stays valid).
$base = (array) DB::table('employees')->whereNull('fired_at')->orderBy('id')->first();
foreach (['Їжакевич Семён [ТЕСТ]', 'ҐУДЗЬ Ірина [ТЕСТ]', 'Ёлкін Олег [ТЕСТ]'] as $i => $name) {
    $row = $base;
    unset($row['id']);
    $row['full_name'] = $name;
    foreach (['user_id', 'manager_id'] as $col) {
        if (array_key_exists($col, $row)) {
            $row[$col] = null;
        }
    }
    foreach (['work_email', 'personal_email', 'email'] as $col) {
        if (array_key_exists($col, $row)) {
            $row[$col] = "mysql-e2e-$i@sinhrm.test";
        }
    }
    if (array_key_exists('phone', $row)) {
        $row['phone'] = '+38000000000'.$i;
    }
    foreach (['created_at', 'updated_at'] as $col) {
        if (array_key_exists($col, $row)) {
            $row[$col] = now();
        }
    }
    DB::table('employees')->insert($row);
}

// CSV formula-injection probe: an employee whose name starts with "=".
$formula = $base;
unset($formula['id']);
$formula['full_name'] = '=HYPERLINK("http://example.test","x") [ТЕСТ]';
foreach (['user_id', 'manager_id', 'work_email', 'personal_email', 'email', 'phone'] as $col) {
    if (array_key_exists($col, $formula)) {
        $formula[$col] = null;
    }
}
$formulaId = DB::table('employees')->insertGetId($formula);

// Gmail connected with a fake token (sending is answered by E2eStubsProvider's Http::fake, nothing leaves the box).
app(App\Modules\GoogleWorkspace\Services\GoogleConnectionStore::class)->connect(
    App\Modules\GoogleWorkspace\Enums\GoogleService::Gmail, 'e2e-refresh', 'e2e-access', now()->addDays(60), 'recruiting-box@sinhrm.test',
    [...App\Modules\GoogleWorkspace\Enums\GoogleService::Gmail->scopes(), App\Modules\GoogleWorkspace\Enums\GoogleService::GMAIL_SEND_SCOPE], $admin->id,
);

// Role users from the demo org chart. "hr": a recruiter user with an employee card made hr_manager (self-decision checks).
$hr = User::query()->where('email', 'demo+hr-2@sinhrm.test')->firstOrFail();
$hr->assignRole('hr_manager');
$recruiter = User::query()->where('email', 'demo+hr-1@sinhrm.test')->firstOrFail();
$employee = DB::table('employees as e')
    ->join('employees as m', 'm.id', '=', 'e.manager_id')
    ->whereNull('e.fired_at')->whereNull('m.fired_at')->whereNotNull('e.user_id')->whereNotNull('m.user_id')
    ->where('e.full_name', 'not like', '%Їжакевич%')
    ->whereNotExists(fn ($q) => $q->from('employees as r')->whereColumn('r.manager_id', 'e.id'))
    ->whereExists(fn ($q) => $q->from('users as u')->whereColumn('u.id', 'e.user_id')->where('u.email', 'like', 'demo+emp-%'))
    ->whereNotNull('m.manager_id') // the manager is not the CEO
    ->orderBy('e.id')
    ->select('e.id', 'e.user_id', 'e.manager_id', 'm.user_id as manager_user_id')
    ->firstOrFail();

$cookie = function (User $user): array {
    $store = app('session.store');
    $store->flush();
    $store->setId(null);
    // DatabaseSessionHandler remembers that the PREVIOUS id existed and would UPDATE (0 rows) instead of INSERT.
    if (method_exists($store->getHandler(), 'setExists')) {
        $store->getHandler()->setExists(false);
    }
    $store->start();
    $store->put(Auth::guard('web')->getName(), $user->getAuthIdentifier());
    $store->put(CredentialSession::VERSION_KEY, $user->credential_version);
    $store->save();
    $name = (string) config('session.cookie');
    $value = app('encrypter')->encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$store->getId(), false);

    return ['name' => $name, 'value' => $value, 'userId' => $user->id];
};

$out = [];
foreach ([
    'admin' => $admin,
    'recruiter' => $recruiter,
    'manager' => User::query()->findOrFail($employee->manager_user_id),
    'employee' => User::query()->findOrFail($employee->user_id),
    'hr' => $hr,
] as $role => $user) {
    $user->forceFill(['status' => 'active'])->save();
    $out[$role] = $cookie($user->fresh());
    $out[$role]['employeeId'] = DB::table('employees')->where('user_id', $user->id)->value('id');
}
// Separate sessions for the faked-clock server (its session writes carry the faked time).
$out['fakeAdmin'] = $cookie($admin->fresh());
$out['fakeEmployee'] = $cookie(User::query()->findOrFail($employee->user_id));
$out['formulaEmployeeId'] = $formulaId;
$out['superadmins'] = User::query()->role('superadmin')->count();
// Legacy single-session keys (first round of the run).
$out += ['name' => $out['admin']['name'], 'value' => $out['admin']['value'], 'userId' => $out['admin']['userId']];
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
