<?php

declare(strict_types=1);

namespace Tests\Unit\People;

use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\Models\Employee;
use App\Modules\People\Models\EmployeeCompensation;
use App\Modules\People\Services\CompensationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use Tests\TestCase;

/** CompensationService reads the history from EmployeeRepository and picks the current record (no DB). */
final class CompensationServiceTest extends TestCase
{
    public function test_current_is_the_newest_record_already_in_force(): void
    {
        Carbon::setTestNow('2026-10-08 12:00:00');
        /** @var EmployeeRepository&MockInterface $employees */
        $employees = $this->mock(EmployeeRepository::class);
        $employees->expects('compensationHistory')->with(5)->andReturn(new Collection([
            $this->record(3, '2026-11-01', '2000'), // future raise
            $this->record(2, '2026-06-01', '1500'),
            $this->record(1, '2025-01-01', '1000'),
        ]));
        $employee = new Employee;
        $employee->id = 5;

        $payload = $this->app->make(CompensationService::class)->payload($employee);

        $this->assertSame(2, $payload['current']['id'] ?? null);
        $this->assertSame([false, true, false], array_column($payload['history'], 'current'));
    }

    /**
     * Night window: 22:30 UTC on 31 Oct is 00:30 on 1 Nov in Kyiv. A raise effective 1 Nov is already in force for
     * the user (UserTime::today), although the UTC date is still 31 Oct.
     */
    public function test_current_follows_the_users_day_not_the_utc_day(): void
    {
        config(['app.user_timezone' => 'Europe/Kyiv']);
        Carbon::setTestNow(Carbon::parse('2026-10-31 22:30:00', 'UTC'));
        /** @var EmployeeRepository&MockInterface $employees */
        $employees = $this->mock(EmployeeRepository::class);
        $employees->expects('compensationHistory')->with(5)->andReturn(new Collection([
            $this->record(3, '2026-11-01', '2000'),
            $this->record(2, '2026-06-01', '1500'),
        ]));
        $employee = new Employee;
        $employee->id = 5;

        $payload = $this->app->make(CompensationService::class)->payload($employee);

        $this->assertSame(3, $payload['current']['id'] ?? null);
        $this->assertSame([true, false], array_column($payload['history'], 'current'));
    }

    private function record(int $id, string $effectiveOn, string $amount): EmployeeCompensation
    {
        $record = new EmployeeCompensation(['effective_on' => $effectiveOn, 'amount' => $amount, 'currency' => 'UAH', 'period' => 'month']);
        $record->id = $id;

        return $record;
    }
}
