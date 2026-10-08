<?php

declare(strict_types=1);

namespace Tests\Unit\Audit;

use App\Modules\Audit\Contracts\AuditHistory;
use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Audit\Services\AuditService;
use Tests\TestCase;

/** People and Recruiting read a record's history through AuditHistory; both Audit contracts resolve to AuditService. */
final class AuditHistoryTest extends TestCase
{
    public function test_history_and_logger_are_the_audit_service(): void
    {
        $this->assertInstanceOf(AuditService::class, $this->app->make(AuditHistory::class));
        $this->assertInstanceOf(AuditService::class, $this->app->make(AuditLogger::class));
    }
}
