<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Default leave types and the company-wide vacation policy (generic values, not company data):
 * vacation — 24 days a year up front; sick — unlimited (no balance); day_off — unpaid, unlimited.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();
        $types = [
            ['code' => 'vacation', 'name' => 'Vacation', 'paid' => true, 'color' => '#4f7cff', 'tracks_balance' => true],
            ['code' => 'sick', 'name' => 'Sick leave', 'paid' => true, 'color' => '#e0625a', 'tracks_balance' => false],
            ['code' => 'day_off', 'name' => 'Day off (unpaid)', 'paid' => false, 'color' => '#8a8f98', 'tracks_balance' => false],
        ];
        foreach ($types as $type) {
            if (DB::table('leave_types')->where('code', $type['code'])->exists()) {
                continue;
            }
            DB::table('leave_types')->insert($type + [
                'unit' => 'days', 'requires_approval' => true, 'active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $vacation = DB::table('leave_types')->where('code', 'vacation')->value('id');
        if ($vacation !== null && ! DB::table('leave_policies')->where('leave_type_id', $vacation)->whereNull('branch_id')->exists()) {
            DB::table('leave_policies')->insert([
                'leave_type_id' => $vacation,
                'branch_id' => null,
                'accrual_mode' => 'yearly_upfront',
                'annual_days' => 24,
                'carry_over_max' => null,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Data migration: the tables are dropped by the previous migration.
    }
};
