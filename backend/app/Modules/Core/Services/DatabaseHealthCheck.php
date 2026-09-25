<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\HealthCheck;
use Illuminate\Database\ConnectionInterface;
use Throwable;

final class DatabaseHealthCheck implements HealthCheck
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function name(): string
    {
        return 'database';
    }

    public function check(): array
    {
        try {
            $this->db->select('select 1');

            return ['ok' => true];
        } catch (Throwable $e) {
            // Never leak connection strings: report only the exception class.
            return ['ok' => false, 'detail' => $e::class];
        }
    }
}
