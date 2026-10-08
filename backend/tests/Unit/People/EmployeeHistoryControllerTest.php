<?php

declare(strict_types=1);

namespace Tests\Unit\People;

use App\Modules\Audit\Contracts\AuditHistory;
use App\Modules\Audit\Http\Requests\HistoryRequest;
use App\Modules\People\Http\Controllers\EmployeeHistoryController;
use App\Modules\People\Models\Employee;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery\MockInterface;
use Tests\TestCase;

/** The employee "History" tab asks Audit through its AuditHistory contract, page and size from the query (no DB). */
final class EmployeeHistoryControllerTest extends TestCase
{
    public function test_history_of_the_employee_with_page_and_size_from_the_query(): void
    {
        $employee = new Employee;
        $employee->id = 5;
        /** @var AuditHistory&MockInterface $audit */
        $audit = $this->mock(AuditHistory::class);
        $audit->expects('history')->with(['employee' => [5]], 2, 10)->andReturn(new LengthAwarePaginator([], 0, 10, 2));
        $request = HistoryRequest::create('/api/people/5/history', 'GET', ['page' => '2', 'perPage' => '10']);

        $response = $this->app->make(EmployeeHistoryController::class)($request, $employee);

        $this->assertCount(0, $response->collection);
    }
}
