<?php

// CI-only (MySQL 8.4 e2e run): sets up the break-glass case on the disposable CI database.
//   on   — demo+hr-3 (has an employee card) becomes superadmin; every other active superadmin/admin is blocked
//          (ids kept in storage/app/e2e-breakglass.json); prints that user's session cookie JSON + employeeId;
//   peer — re-activates the demo admin (a peer exists again → break-glass off);
//   off  — re-activates everyone blocked by "on" and takes superadmin back.
// Never committed to main.

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

$file = storage_path('app/e2e-breakglass.json');
$bg = User::query()->where('email', 'demo+hr-3@sinhrm.test')->firstOrFail();
$mode = $argv[1] ?? 'on';

if ($mode === 'on') {
    $bg->assignRole('superadmin');
    $bg->forceFill(['status' => 'active'])->save();
    $others = User::query()->role(['superadmin', 'admin'])->where('status', 'active')->whereKeyNot($bg->id)->pluck('id')->all();
    file_put_contents($file, json_encode($others));
    User::query()->whereIn('id', $others)->update(['status' => 'blocked']);
    app('cache')->flush();

    $store = app('session.store');
    $store->start();
    $store->put(Auth::guard('web')->getName(), $bg->getAuthIdentifier());
    $store->put(CredentialSession::VERSION_KEY, $bg->fresh()->credential_version);
    $store->save();
    $name = (string) config('session.cookie');
    $value = app('encrypter')->encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$store->getId(), false);
    echo json_encode(['name' => $name, 'value' => $value, 'userId' => $bg->id, 'employeeId' => DB::table('employees')->where('user_id', $bg->id)->value('id'), 'blocked' => count($others)]).PHP_EOL;
} elseif ($mode === 'peer') {
    User::query()->where('email', 'demo+admin@sinhrm.test')->update(['status' => 'active']);
    app('cache')->flush();
    echo "peer\n";
} else {
    $others = json_decode((string) @file_get_contents($file), true) ?: [];
    User::query()->whereIn('id', $others)->update(['status' => 'active']);
    $bg->removeRole('superadmin');
    app('cache')->flush();
    echo "off\n";
}
