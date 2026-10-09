<?php

declare(strict_types=1);

namespace Tests\Feature\Scripts;

use Illuminate\Support\Facades\DB;
use Tests\Support\MysqlSchemaAssertions;
use Tests\TestCase;

/** MySQL requires dropping the employee foreign key before its supporting indexes. */
final class GeneralizeTasksMigrationTest extends TestCase
{
    use MysqlSchemaAssertions;

    private const string MIGRATION = 'app/Modules/Scripts/Database/Migrations/2026_10_03_100001_generalize_tasks_table.php';

    public function test_down_then_up_restore_tasks_schema(): void
    {
        $this->artisan('migrate')->assertSuccessful();
        $migration = require base_path(self::MIGRATION);

        $migration->down();
        $this->assertSame([], $this->indexParts('tasks', 'tasks_employee_id_rule_key_unique'));
        $this->assertSame([], $this->indexParts('tasks', 'tasks_employee_id_done_at_index'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('tasks', 'employee_id'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('tasks', 'link'));

        $migration->up();
        $this->assertSame(['employee_id', 'rule_key'], $this->indexParts('tasks', 'tasks_employee_id_rule_key_unique'));
        $this->assertSame(['employee_id', 'done_at'], $this->indexParts('tasks', 'tasks_employee_id_done_at_index'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('tasks', 'employee_id'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('tasks', 'link'));
        $this->assertSame(1, (int) DB::selectOne("select count(*) as n from information_schema.table_constraints where table_schema = database() and table_name = 'tasks' and constraint_name = 'tasks_employee_id_foreign'")->n);
    }
}
