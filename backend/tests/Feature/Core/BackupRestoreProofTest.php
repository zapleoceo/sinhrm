<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Directory\Models\Branch;
use App\Modules\Integrations\Contracts\SecretVault;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Services\ApplicationService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** CI-only phases intentionally do not use RefreshDatabase: verify must read the restored dump. */
final class BackupRestoreProofTest extends TestCase
{
    private const string SECRET = 'synthetic-restore-fixture';

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(getenv('RESTORE_PROOF_PHASE'), ['seed', 'verify'], true)) {
            $this->markTestSkipped('Only the isolated backup-restore workflow runs these phases.');
        }
        $this->assertSame('testing', config('app.env'));
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('127.0.0.1', config('database.connections.pgsql.host'));
        $this->assertSame(
            getenv('RESTORE_PROOF_PHASE') === 'seed' ? 'app_dump_source' : 'app_dump_restored',
            DB::connection()->getDatabaseName(),
        );
    }

    public function test_seed_synthetic_related_rows_and_encrypted_vault(): void
    {
        if (getenv('RESTORE_PROOF_PHASE') !== 'seed') {
            $this->markTestSkipped('Seed phase only.');
        }
        $user = User::factory()->create(['name' => 'Restore fixture', 'email' => 'restore@example.test']);
        $branch = Branch::factory()->create(['name' => 'Restore fixture branch']);
        $vacancy = Vacancy::factory()->create(['title' => 'Restore fixture vacancy', 'branch_id' => $branch->id, 'recruiter_id' => $user->id]);
        $candidate = Candidate::factory()->create(['full_name' => 'Restore fixture candidate', 'email' => 'candidate@example.test']);
        $this->app->make(ApplicationService::class)->apply($user, $candidate, $vacancy);
        $this->app->make(SecretVault::class)->put('restore-proof', 'fixture', self::SECRET, $user->id);
        $this->assertRestoredData();
    }

    public function test_restored_database_has_rows_relations_sequences_and_readable_vault(): void
    {
        if (getenv('RESTORE_PROOF_PHASE') !== 'verify') {
            $this->markTestSkipped('Verify phase only.');
        }
        $this->assertRestoredData();
        $candidate = Candidate::query()->sole();
        $next = Candidate::factory()->create(['email' => 'after-restore@example.test']);
        $this->assertGreaterThan($candidate->id, $next->id, 'Sequence restored with its data.');
        $candidate->delete();
        $this->assertSame(0, Application::query()->count(), 'Restored foreign-key cascade remains active.');
    }

    private function assertRestoredData(): void
    {
        $this->assertGreaterThan(0, DB::table('migrations')->count());
        $user = User::query()->where('email', 'restore@example.test')->sole();
        $application = Application::query()->with(['candidate', 'vacancy.recruiter', 'vacancy.branch', 'stage', 'stageChanges'])->sole();
        $this->assertSame('candidate@example.test', $application->candidate->email);
        $this->assertSame($user->id, $application->vacancy->recruiter->id);
        $this->assertSame('Restore fixture branch', $application->vacancy->branch->name);
        $this->assertSame($application->vacancy->pipeline_id, $application->stage->pipeline_id);
        $change = $application->stageChanges->sole();
        $this->assertSame($user->id, $change->by_user_id);
        $touch = Touchpoint::query()->sole();
        $this->assertSame($application->id, $touch->application_id);
        $this->assertSame($change->id, $touch->stage_change_id);
        $this->assertSame($application->candidate_id, $touch->candidate_id);
        $raw = DB::table('integration_secrets')->where('name', 'fixture')->sole();
        $this->assertNotSame(self::SECRET, $raw->value, 'Dump contains ciphertext.');
        $this->assertSame($user->id, $raw->updated_by);
        $this->assertTrue(
            $this->app->make(SecretVault::class)->get('restore-proof', 'fixture') === self::SECRET,
            'The unchanged CI APP_KEY decrypts the restored vault.',
        );
    }
}
