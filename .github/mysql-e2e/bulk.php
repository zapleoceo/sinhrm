<?php

// CI-only (MySQL 8.4 e2e run): 1100 synthetic employees (copies of a demo row, " [ТЕСТ]" names, every 4th without a
// manager / position / department → NULL sort keys) and 1100 synthetic candidates, for the pagination / sort checks.
// Bulk inserts in chunks of 500. Never committed to main.

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$base = (array) DB::table('employees')->whereNull('fired_at')->whereNotNull('manager_id')->orderBy('id')->first();
unset($base['id']);
$rows = [];
for ($i = 0; $i < 1100; $i++) {
    $row = $base;
    $row['full_name'] = sprintf('Масовий %s%04d [ТЕСТ]', ['А', 'а', 'Є', 'є', 'Ї', 'і', 'Ґ', 'Z', 'z', 'É'][$i % 10], $i);
    $row['user_id'] = null;
    foreach (['work_email', 'personal_email', 'email'] as $col) {
        if (array_key_exists($col, $row)) {
            $row[$col] = $col === 'work_email' ? sprintf('bulk-%04d@sinhrm.test', $i) : null;
        }
    }
    if (array_key_exists('phone', $row)) {
        $row['phone'] = null;
    }
    if ($i % 4 === 0) {
        foreach (['manager_id', 'position_id', 'department_id'] as $col) {
            if (array_key_exists($col, $row)) {
                $row[$col] = null;
            }
        }
    }
    foreach (['created_at', 'updated_at'] as $col) {
        if (array_key_exists($col, $row)) {
            $row[$col] = now();
        }
    }
    $rows[] = $row;
}
foreach (array_chunk($rows, 500) as $chunk) {
    DB::table('employees')->insert($chunk);
}

$cbase = (array) DB::table('candidates')->orderBy('id')->first();
unset($cbase['id']);
$rows = [];
for ($i = 0; $i < 1100; $i++) {
    $row = $cbase;
    $row['full_name'] = sprintf('Кандидат-масовий %04d [ТЕСТ]', $i);
    foreach (['email', 'phone', 'telegram', 'telegram_username', 'linkedin_url', 'source_url', 'profile_url'] as $col) {
        if (array_key_exists($col, $row)) {
            $row[$col] = $col === 'email' && $i % 3 !== 0 ? sprintf('bulk-cand-%04d@sinhrm.test', $i) : null;
        }
    }
    foreach (['created_at', 'updated_at'] as $col) {
        if (array_key_exists($col, $row)) {
            $row[$col] = now()->subMinutes($i);
        }
    }
    $rows[] = $row;
}
try {
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('candidates')->insert($chunk);
    }
} catch (Throwable $e) {
    echo 'candidates bulk insert skipped: ', substr($e->getMessage(), 0, 300), "\n";
}
printf("bulk: employees=%d candidates=%d\n", DB::table('employees')->count(), DB::table('candidates')->count());
