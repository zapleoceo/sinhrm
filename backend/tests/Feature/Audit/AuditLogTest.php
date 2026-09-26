<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\User;
use App\Modules\Audit\Contracts\AuditLogRepository;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Audit\Services\AuditRetentionJob;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Directory\Models\Branch;
use App\Modules\Documents\Models\Document;
use App\Modules\People\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

final class AuditLogTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    private const string BOT_TOKEN = 'synthetic-audit-token-4242';

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superadmin = User::factory()->withRole(UserRole::Superadmin)->create();
    }

    public function test_employee_changes_are_logged_with_actor_and_personal_fields_masked(): void
    {
        $hr = User::factory()->withRole(UserRole::HrManager)->create();
        $this->actingAs($hr);
        $employee = Employee::factory()->create(['phone' => '+380501112233', 'personal_email' => 'private@example.test']);
        $employee->update(['phone' => '+380509998877', 'full_name' => 'Renamed Person']);

        $created = $this->entry('employee', $employee->id, 'created');
        $this->assertSame($hr->id, $created->user_id);
        $this->assertSame('***', $created->changes['phone']['to'] ?? null);
        $this->assertSame('***', $created->changes['personal_email']['to'] ?? null);

        $updated = $this->entry('employee', $employee->id, 'updated');
        $this->assertEquals(['from' => '***', 'to' => '***'], $updated->changes['phone'] ?? null);
        $this->assertEquals(['from' => '***', 'to' => '***'], $updated->changes['full_name'] ?? null);

        $raw = (string) json_encode(AuditEntry::query()->get()->toArray());
        $this->assertStringNotContainsString('380509998877', $raw);
        $this->assertStringNotContainsString('private@example.test', $raw);
    }

    public function test_status_change_is_its_own_action_and_timestamp_only_updates_are_skipped(): void
    {
        $this->actingAs($this->superadmin);
        $employee = Employee::factory()->create();
        $employee->update(['status' => 'terminated']);
        $employee->touch();

        $this->assertSame('terminated', $this->entry('employee', $employee->id, 'status_changed')->changes['status']['to'] ?? null);
        $this->assertSame(0, AuditEntry::query()->where('entity_type', 'employee')->where('action', 'updated')->count());
    }

    public function test_integration_secret_set_and_clear_are_logged_without_the_value(): void
    {
        $this->actingAs($this->superadmin)
            ->putJson('/api/integrations/telegram_business', ['secrets' => ['bot_token' => self::BOT_TOKEN]])->assertOk();
        $this->actingAs($this->superadmin)
            ->putJson('/api/integrations/telegram_business', ['secrets' => ['bot_token' => null]])->assertOk();

        $set = AuditEntry::query()->where('action', 'secret_set')->sole();
        $this->assertSame('integration', $set->entity_type);
        $this->assertSame(['secret' => 'bot_token'], $set->meta);
        $this->assertSame($this->superadmin->id, $set->user_id);
        $this->assertSame(1, AuditEntry::query()->where('action', 'secret_cleared')->count());

        $raw = (string) json_encode(AuditEntry::query()->get()->toArray());
        $this->assertStringNotContainsString('synthetic-audit-token', $raw);
        $this->assertStringNotContainsString('4242', $raw);
    }

    public function test_role_change_is_logged(): void
    {
        $user = User::factory()->withRole(UserRole::Viewer)->create();

        $this->actingAs($this->superadmin)->patchJson("/api/users/{$user->id}", ['role' => 'employee'])->assertOk();
        $this->actingAs($this->superadmin)->patchJson("/api/users/{$user->id}", ['status' => 'blocked'])->assertOk();

        $role = $this->entry('user', $user->id, 'role_changed');
        $this->assertEquals(['from' => 'viewer', 'to' => 'employee'], $role->changes['role'] ?? null);
        $this->assertSame($this->superadmin->id, $role->user_id);
        $this->assertEquals(['from' => 'active', 'to' => 'blocked'], $this->entry('user', $user->id, 'status_changed')->changes['status'] ?? null);
    }

    public function test_stage_move_is_logged_and_shown_in_candidate_history_only_to_those_who_see_the_candidate(): void
    {
        [$north, $south] = Branch::factory()->count(2)->create()->all();
        $vacancy = $this->vacancyIn($north);
        $application = $this->applied($vacancy, ['email' => 'candidate.secret@example.test']);
        $recruiter = $this->userWith(UserRole::Recruiter, [$north]);
        $stranger = $this->userWith(UserRole::Recruiter, [$south]);

        $this->actingAs($recruiter)->postJson("/api/applications/{$application->id}/move", ['stage_id' => $this->stageAt(2)->id])->assertOk();

        $moved = $this->entry('application', $application->id, 'stage_changed');
        $this->assertSame($recruiter->id, $moved->user_id);
        $this->assertSame(['candidate_id' => $application->candidate_id], $moved->meta);

        $this->actingAs($recruiter)->getJson("/api/candidates/{$application->candidate_id}/history")
            ->assertOk()
            ->assertJsonPath('data.0.action', 'stage_changed')
            ->assertJsonPath('data.0.user.id', $recruiter->id)
            ->assertJsonMissing(['to' => 'candidate.secret@example.test']);
        $this->actingAs($stranger)->getJson("/api/candidates/{$application->candidate_id}/history")->assertForbidden();
    }

    public function test_employee_history_is_for_people_managers_only(): void
    {
        $this->actingAs($this->superadmin);
        $employee = Employee::factory()->create();

        $this->actingAs(User::factory()->withRole(UserRole::HrManager)->create())
            ->getJson("/api/people/{$employee->id}/history")
            ->assertOk()
            ->assertJsonPath('data.0.action', 'created')
            ->assertJsonPath('data.0.entity_type', 'employee');
        $this->actingAs(User::factory()->withRole(UserRole::Employee)->create())
            ->getJson("/api/people/{$employee->id}/history")->assertForbidden();
    }

    public function test_global_log_is_superadmin_only_and_filters(): void
    {
        $this->actingAs($this->superadmin);
        $employee = Employee::factory()->create();
        $employee->update(['status' => 'terminated']);

        $this->getJson('/api/audit?entity_type=employee&action=status_changed&from='.Carbon::now()->toDateString().'&to='.Carbon::now()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.entity_id', $employee->id)
            ->assertJsonPath('meta.total', 1);
        $this->getJson('/api/audit?user_id='.$this->superadmin->id.'&perPage=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/audit?from=2020-01-01&to=2020-01-02')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/audit?action=nope')->assertUnprocessable();
        $this->getJson('/api/audit/options')->assertOk()
            ->assertJsonPath('users.0.id', $this->superadmin->id)
            ->assertJsonPath('entity_types', ['employee', 'user']);

        foreach ([UserRole::HrManager, UserRole::Admin, UserRole::Recruiter] as $role) {
            $this->actingAs(User::factory()->withRole($role)->create())->getJson('/api/audit')->assertForbidden();
        }
    }

    public function test_retention_job_deletes_rows_older_than_a_year(): void
    {
        AuditEntry::query()->delete();
        $now = Carbon::parse('2026-10-21 12:00:00');
        AuditEntry::query()->create(['entity_type' => 'employee', 'entity_id' => 1, 'action' => 'updated', 'created_at' => $now->copy()->subDays(366)]);
        AuditEntry::query()->create(['entity_type' => 'employee', 'entity_id' => 1, 'action' => 'updated', 'created_at' => $now->copy()->subDays(10)]);

        $job = collect($this->app->tagged(ScheduledJob::class))->first(fn (ScheduledJob $j): bool => $j->name() === 'audit.retention');
        $this->assertInstanceOf(AuditRetentionJob::class, $job);
        $this->assertSame(['deleted' => 1, 'batches' => 1, 'complete' => true], $job->run($now));
        $this->assertSame(0, $job->run($now)['deleted']);
        $this->assertSame(1, AuditEntry::query()->count());
    }

    public function test_retention_deletes_in_bounded_batches_and_stops_on_time_budget(): void
    {
        $repo = $this->createMock(AuditLogRepository::class);
        $repo->expects($this->exactly(3))->method('purgeOlderThan')
            ->with($this->anything(), AuditRetentionJob::BATCH)
            ->willReturnOnConsecutiveCalls(AuditRetentionJob::BATCH, AuditRetentionJob::BATCH, 7);
        $this->assertSame(['deleted' => 2007, 'batches' => 3, 'complete' => true], (new AuditRetentionJob($repo))->run(Carbon::now()));

        // Budget spent after the first batch: stop, the next cron run continues.
        $slow = $this->createMock(AuditLogRepository::class);
        $slow->expects($this->once())->method('purgeOlderThan')->willReturn(AuditRetentionJob::BATCH);
        $t = 0.0;
        $clock = function () use (&$t): float {
            $t += 30.0;

            return $t;
        };
        $this->assertSame(['deleted' => 1000, 'batches' => 1, 'complete' => false], (new AuditRetentionJob($slow, $clock))->run(Carbon::now()));
    }

    public function test_document_body_and_file_path_are_never_stored(): void
    {
        $this->actingAs($this->superadmin);
        $employee = Employee::factory()->create();
        $document = Document::query()->create([
            'employee_id' => $employee->id, 'title' => 'Offer', 'category' => 'other',
            'content_md' => 'Salary 99999 UAH, home address Secret street', 'file_path' => 'docs/private-scan.pdf',
        ]);
        $document->update(['content_md' => 'Salary 12345 UAH']);

        $raw = (string) json_encode(AuditEntry::query()->where('entity_type', 'document')->get()->toArray());
        $this->assertStringNotContainsString('99999', $raw);
        $this->assertStringNotContainsString('12345', $raw);
        $this->assertStringNotContainsString('private-scan', $raw);
        $this->assertEquals(['from' => '***', 'to' => '***'], $this->entry('document', $document->id, 'updated')->changes['content_md'] ?? null);
    }

    public function test_rolled_back_write_leaves_no_audit_row(): void
    {
        $this->actingAs($this->superadmin);
        try {
            DB::transaction(function (): void {
                Employee::factory()->create(['full_name' => 'Ghost Person']);
                throw new RuntimeException('business failure');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, AuditEntry::query()->where('entity_type', 'employee')->count());
    }

    public function test_audit_failure_never_breaks_the_business_write(): void
    {
        $repo = $this->createMock(AuditLogRepository::class);
        $repo->method('store')->willThrowException(new RuntimeException('audit db down'));
        $this->app->instance(AuditLogRepository::class, $repo);
        $log = $this->createMock(LoggerInterface::class);
        $log->expects($this->atLeastOnce())->method('error')->with('audit.write_failed', $this->anything());
        $this->app->instance(LoggerInterface::class, $log);

        $this->actingAs($this->superadmin);
        $employee = Employee::factory()->create(['full_name' => 'Still Saved']);

        $this->assertTrue(Employee::query()->whereKey($employee->id)->exists());
    }

    public function test_privacy_erase_leaves_no_personal_value_in_the_log_and_export_has_the_audit_section(): void
    {
        $branch = Branch::factory()->create();
        $application = $this->applied($this->vacancyIn($branch), [
            'full_name' => 'Synthetic Auditperson', 'phone' => '+380500000777', 'email' => 'synthetic.audit@example.test',
            'telegram_username' => 'synthetic_audit_tg',
        ]);
        $id = $application->candidate_id;
        // A row written under an older, looser policy: erase must re-mask it.
        AuditEntry::query()->create([
            'entity_type' => 'candidate', 'entity_id' => $id, 'action' => 'updated',
            'changes' => ['full_name' => ['from' => 'Synthetic Auditperson', 'to' => 'Synthetic Auditperson Jr']], 'created_at' => Carbon::now(),
        ]);

        $export = $this->actingAs($this->superadmin)->get("/api/privacy/candidate/{$id}/export")->assertOk();
        $sections = json_decode((string) $export->getContent(), true)['sections'];
        $this->assertNotEmpty($sections['audit_log']);
        $this->assertContains('created', array_column($sections['audit_log'], 'action'));

        $this->actingAs($this->superadmin)->postJson("/api/privacy/candidate/{$id}/erase", ['reason' => 'Written request', 'confirm' => true])
            ->assertOk()->assertJsonPath('data.erased', true);

        $raw = (string) json_encode(AuditEntry::query()->get()->toArray(), JSON_UNESCAPED_UNICODE);
        foreach (['Synthetic Auditperson', '380500000777', 'synthetic.audit@example.test', 'synthetic_audit_tg', 'Видалений кандидат'] as $value) {
            $this->assertStringNotContainsString($value, $raw);
        }
        $this->assertGreaterThan(0, AuditEntry::query()->where('entity_type', 'candidate')->where('entity_id', $id)->where('action', 'updated')->count());
    }

    private function entry(string $type, int $id, string $action): AuditEntry
    {
        return AuditEntry::query()->where('entity_type', $type)->where('entity_id', $id)->where('action', $action)->sole();
    }
}
