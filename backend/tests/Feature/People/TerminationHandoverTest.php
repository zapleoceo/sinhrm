<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Models\Employee;
use App\Modules\Scripts\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/**
 * Optional handover colleague on termination (owner decision 2026-10-07, A): validation, permissions, who sees it.
 * The task itself — tests/Feature/Workflows/TerminationHandoverTaskTest.php. Synthetic data only.
 * Summer, Kyiv = UTC+3: now is 12:00 Kyiv on 2026-07-14.
 */
final class TerminationHandoverTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    /** @var array{head: Employee, lead: Employee, worker: Employee, peer: Employee, other: Employee} */
    private array $org;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.user_timezone' => 'Europe/Kyiv', 'app.timezone' => 'UTC']);
        Carbon::setTestNow('2026-07-14 09:00:00');
        $this->org = $this->org();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_handover_validation_and_permissions(): void
    {
        $lead = $this->userOf($this->org['lead']);
        $url = '/api/people/'.$this->org['worker']->id.'/terminate';
        $gone = Employee::factory()->terminated()->create();

        foreach ([$this->org['worker']->id, 999999, $gone->id] as $bad) {
            $this->actingAs($lead)->postJson($url, ['fired_at' => '2026-07-20', 'handover_to_employee_id' => $bad])
                ->assertStatus(422)->assertJsonPath('code', 'invalid_handover');
        }
        $this->actingAs($lead)->postJson($url, ['fired_at' => '2026-07-20', 'handover_to_employee_id' => 'abc'])->assertStatus(422);
        // a peer may not terminate at all, with or without a colleague
        $this->actingAs($this->userOf($this->org['peer']))
            ->postJson($url, ['fired_at' => '2026-07-20', 'handover_to_employee_id' => $this->org['other']->id])->assertForbidden();
        $this->assertNull($this->org['worker']->refresh()->fired_at, 'nothing saved on a rejected request');

        // String id, as a form would send it; the profile shows only id and name, to those who see job data.
        $this->actingAs($lead)->postJson($url, ['fired_at' => '2026-07-20', 'handover_to_employee_id' => (string) $this->org['peer']->id])
            ->assertOk()
            ->assertJsonPath('data.handover_to', ['id' => $this->org['peer']->id, 'full_name' => 'Peer Person']);
        $this->actingAs($this->userOf($this->org['head']))->getJson('/api/people/'.$this->org['worker']->id)
            ->assertJsonPath('data.handover_to.full_name', 'Peer Person');
        $this->actingAs($this->userOf($this->org['other']))->getJson('/api/people/'.$this->org['worker']->id)
            ->assertJsonMissingPath('data.handover_to');
        $this->assertSame(0, Task::query()->count(), 'no task before the termination applies');
    }
}
