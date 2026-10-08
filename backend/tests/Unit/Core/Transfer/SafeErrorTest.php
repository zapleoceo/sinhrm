<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Transfer;

use App\Modules\Core\Transfer\SafeError;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SafeErrorTest extends TestCase
{
    public function test_hides_cell_values_with_apostrophes_and_sql_bindings(): void
    {
        $driver = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'O'Brien@x.test' for key 'users.users_email_unique'");
        $query = new QueryException('transfer_target', 'insert into `users` (`email`) values (?)', ["O'Brien@x.test"], $driver);

        $text = SafeError::text($query);

        $this->assertStringNotContainsString('Brien', $text);
        $this->assertStringNotContainsString('insert into', $text);
        $this->assertStringContainsString('SQLSTATE[23000]', $text);
        $this->assertStringContainsString('1062 Duplicate entry', $text);
    }

    public function test_hides_double_quoted_logins_hosts_and_connection_secrets(): void
    {
        $text = SafeError::text(new PDOException('SQLSTATE[08006] connection to server at "db.internal" failed: password authentication failed for user "hr_admin"'));
        $this->assertStringNotContainsString('db.internal', $text);
        $this->assertStringNotContainsString('hr_admin', $text);
        $this->assertStringContainsString('SQLSTATE[08006]', $text);

        $url = 'mysql://user:abc@db.example:3306/app';
        $masked = SafeError::text(new RuntimeException("cannot reach {$url} with abc"), [$url, 'abc']);
        $this->assertStringNotContainsString('abc', $masked);
    }
}
