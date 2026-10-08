<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Transfer;

use App\Modules\Core\Services\Transfer\SafeError;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SafeErrorTest extends TestCase
{
    public function test_hides_cell_values_bindings_and_connection_secrets(): void
    {
        $driver = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'anna@x.test' for key 'users.users_email_unique'");
        $query = new QueryException('transfer_target', 'insert into `users` (`email`) values (?)', ['anna@x.test'], $driver);

        $text = SafeError::text($query);

        $this->assertStringNotContainsString('anna@x.test', $text);
        $this->assertStringContainsString("for key 'users.users_email_unique'", $text);

        $url = 'mysql://user:VeryLongPassword1@db.example:3306/app';
        $masked = SafeError::text(new RuntimeException("cannot reach {$url}"), [$url, 'VeryLongPassword1']);
        $this->assertStringNotContainsString('VeryLongPassword1', $masked);
    }
}
