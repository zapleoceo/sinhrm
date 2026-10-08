<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Directory\Models\Branch;
use App\Modules\Documents\Contracts\DocumentStorage;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Repositories\DatabaseDocumentStorage;
use App\Modules\Integrations\Contracts\SecretVault;
use App\Modules\People\Models\Employee;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Services\ApplicationService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** CI-only phases read a real mysqldump restore, so RefreshDatabase must not erase the fixture. */
final class MySqlBackupRestoreProofTest extends TestCase
{
    private const string SECRET = 'synthetic-mysql-restore-secret';

    private const string PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        $phase = getenv('MYSQL_RESTORE_PROOF_PHASE');
        if (! in_array($phase, ['seed', 'verify'], true)) {
            $this->markTestSkipped('Only the disposable MySQL restore workflow runs this proof.');
        }
        $this->assertSame('true', getenv('GITHUB_ACTIONS'));
        $this->assertSame('testing', config('app.env'));
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('127.0.0.1', DB::connection()->getConfig('host'));
        $this->assertEmpty(DB::connection()->getConfig('url'));
        $this->assertSame('', (string) getenv('DB_URL'));
        $this->assertSame('', (string) getenv('DATABASE_URL'));
        $this->assertSame($phase === 'seed' ? 'app_mysql_dump_source' : 'app_mysql_dump_restored', DB::connection()->getDatabaseName());
    }

    public function test_seed_synthetic_graph_attachment_and_vault(): void
    {
        if (getenv('MYSQL_RESTORE_PROOF_PHASE') !== 'seed') {
            $this->markTestSkipped('Seed phase only.');
        }
        $user = User::factory()->create(['name' => 'MySQL restore fixture', 'email' => 'mysql-restore@example.test']);
        $branch = Branch::factory()->create(['name' => 'MySQL restore branch']);
        $vacancy = Vacancy::factory()->create(['title' => 'MySQL restore vacancy', 'branch_id' => $branch->id, 'recruiter_id' => $user->id]);
        $candidate = Candidate::factory()->create(['full_name' => 'MySQL restore candidate', 'email' => 'mysql-candidate@example.test']);
        app(ApplicationService::class)->apply($user, $candidate, $vacancy);
        $employee = Employee::factory()->create(['branch_id' => $branch->id]);
        $document = Document::query()->create(['employee_id' => $employee->id, 'title' => 'MySQL restore document']);
        app(DatabaseDocumentStorage::class)->put($document, $this->pdf(), 'restore.pdf');
        app(SecretVault::class)->put('mysql-restore-proof', 'fixture', self::SECRET, $user->id);
        $this->assertFixture();
    }

    public function test_restored_mysql_has_graph_attachment_vault_and_auto_increment(): void
    {
        if (getenv('MYSQL_RESTORE_PROOF_PHASE') !== 'verify') {
            $this->markTestSkipped('Verify phase only.');
        }
        $this->assertFixture();
        $candidate = Candidate::query()->where('email', 'mysql-candidate@example.test')->sole();
        $next = Candidate::factory()->create(['email' => 'after-mysql-restore@example.test']);
        $this->assertGreaterThan($candidate->id, $next->id, 'AUTO_INCREMENT restored with its data.');
        $candidate->delete();
        $this->assertSame(0, Application::query()->count(), 'Foreign-key cascade still works after restore.');
    }

    private function assertFixture(): void
    {
        $this->assertGreaterThan(0, DB::table('migrations')->count());
        $user = User::query()->where('email', 'mysql-restore@example.test')->sole();
        $application = Application::query()->with(['candidate', 'vacancy.recruiter', 'vacancy.branch', 'stage', 'stageChanges'])->sole();
        $this->assertSame('mysql-candidate@example.test', $application->candidate->email);
        $this->assertSame($user->id, $application->vacancy->recruiter->id);
        $this->assertSame('MySQL restore branch', $application->vacancy->branch->name);
        $this->assertSame($application->vacancy->pipeline_id, $application->stage->pipeline_id);
        $change = $application->stageChanges->sole();
        $touch = Touchpoint::query()->sole();
        $this->assertSame($application->id, $touch->application_id);
        $this->assertSame($change->id, $touch->stage_change_id);

        $document = Document::query()->where('title', 'MySQL restore document')->sole();
        $file = app(DatabaseDocumentStorage::class)->get($document);
        $this->assertNotNull($file);
        $this->assertSame(DocumentStorage::MAX_BYTES, $file->size);
        $this->assertSame($this->pdf(), $file->content);
        $this->assertSame(hash('sha256', $file->content), DB::table('documents_files')->where('document_id', $document->id)->value('sha256'));

        $raw = DB::table('integration_secrets')->where('name', 'fixture')->sole();
        $this->assertNotSame(self::SECRET, $raw->value);
        $this->assertSame($user->id, $raw->updated_by);
        $this->assertSame(self::SECRET, app(SecretVault::class)->get('mysql-restore-proof', 'fixture'));
    }

    private function pdf(): string
    {
        return self::PDF.str_repeat(' ', DocumentStorage::MAX_BYTES - strlen(self::PDF));
    }
}
