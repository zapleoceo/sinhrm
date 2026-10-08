<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use App\Modules\Documents\Http\Controllers\DocumentTemplateController;
use App\Modules\Documents\Http\Requests\PreviewTemplateRequest;
use App\Modules\People\Contracts\EmployeeLookup;
use App\Modules\People\Models\Employee;
use Mockery\MockInterface;
use Tests\TestCase;

/** Documents takes the employee of a template preview from People's EmployeeLookup contract (no DB). */
final class DocumentsPeopleContractsTest extends TestCase
{
    public function test_preview_fills_the_template_with_the_looked_up_employee(): void
    {
        $employee = new Employee(['full_name' => 'Olena Example', 'hired_at' => '2025-01-15']);
        $employee->id = 5;
        /** @var EmployeeLookup&MockInterface $employees */
        $employees = $this->mock(EmployeeLookup::class);
        $employees->expects('find')->with(5)->andReturn($employee);
        $request = PreviewTemplateRequest::create('/api/documents/templates/preview', 'POST', ['body' => 'Dear {ПІБ}', 'employee_id' => 5]);

        $response = $this->app->make(DocumentTemplateController::class)->preview($request);

        $this->assertStringContainsString('Olena Example', (string) json_encode($response->getData(true), JSON_UNESCAPED_UNICODE));
    }
}
