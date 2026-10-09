<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use Illuminate\Support\Facades\DB;
use Tests\Support\MysqlSchemaAssertions;
use Tests\TestCase;

/**
 * 2026_10_23_100001 on MySQL 8.4: the explicit filter indexes replace the implicit foreign-key indexes, so down() has to
 * drop and recreate the foreign keys (error 1553 otherwise). No RefreshDatabase: DDL commits implicitly on MySQL; the test
 * runs down() then up() and leaves the schema as it found it.
 */
final class DirectoryFilterIndexesMigrationTest extends TestCase
{
    use MysqlSchemaAssertions;

    private const string MIGRATION = 'app/Modules/People/Database/Migrations/2026_10_23_100001_add_directory_filter_indexes_to_employees.php';

    public function test_down_and_up_keep_the_foreign_keys_and_toggle_the_filter_indexes(): void
    {
        $this->artisan('migrate')->assertSuccessful();
        $migration = require base_path(self::MIGRATION);

        $migration->down();
        $this->assertSame([], $this->indexParts('employees', 'employees_department_id_index'));
        $this->assertSame([], $this->indexParts('employees', 'employees_position_id_index'));
        $this->assertSame(['employees_department_id_foreign', 'employees_position_id_foreign'], $this->foreignKeys());

        $migration->up();
        $this->assertSame(['department_id'], $this->indexParts('employees', 'employees_department_id_index'));
        $this->assertSame(['position_id'], $this->indexParts('employees', 'employees_position_id_index'));
        $this->assertSame(['employees_department_id_foreign', 'employees_position_id_foreign'], $this->foreignKeys());
    }

    /** @return list<string> */
    private function foreignKeys(): array
    {
        $rows = DB::select(
            "select constraint_name as n from information_schema.table_constraints
             where table_schema = database() and table_name = 'employees' and constraint_type = 'FOREIGN KEY'
             and constraint_name in ('employees_department_id_foreign', 'employees_position_id_foreign') order by constraint_name",
        );

        return array_map(static fn (object $r): string => (string) $r->n, $rows);
    }
}
