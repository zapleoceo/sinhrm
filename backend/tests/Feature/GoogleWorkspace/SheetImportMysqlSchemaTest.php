<?php

declare(strict_types=1);

namespace Tests\Feature\GoogleWorkspace;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MysqlSchemaAssertions;
use Tests\TestCase;

/** sheet_imports.spreadsheet_id is an opaque Google id: binary collation on MySQL 8.4 (ADR 0010). */
final class SheetImportMysqlSchemaTest extends TestCase
{
    use MysqlSchemaAssertions, RefreshDatabase;

    public function test_spreadsheet_id_is_binary_while_the_sheet_title_keeps_the_default_collation(): void
    {
        $this->assertColumnCollation('sheet_imports', 'spreadsheet_id', 'utf8mb4_bin');
        $this->assertColumnCollation('sheet_imports', 'sheet', 'utf8mb4_0900_ai_ci');
    }
}
