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
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Services\ApplicationService;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/** Invoked only by the disposable PostgreSQL-to-MySQL transfer workflow, without RefreshDatabase. */
final class MySqlTransferProofTest extends TestCase
{
    private const string SECRET = 'synthetic-transfer-secret';

    private const string PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        $phase = getenv('MYSQL_TRANSFER_PHASE');
        if (! in_array($phase, ['seed', 'verify', 'write', 'rollback'], true)) {
            $this->markTestSkipped('Only the isolated cross-database workflow runs this proof.');
        }
        $driver = in_array($phase, ['seed', 'rollback'], true) ? 'pgsql' : 'mysql';
        $database = $driver === 'pgsql' ? 'app_transfer_source' : 'app_transfer_target';
        $this->assertSame('testing', config('app.env'));
        $this->assertSame($driver, DB::connection()->getDriverName());
        $this->assertSame('127.0.0.1', config("database.connections.$driver.host"));
        $this->assertSame($database, DB::connection()->getDatabaseName());
    }

    public function test_transfer_phase(): void
    {
        switch (getenv('MYSQL_TRANSFER_PHASE')) {
            case 'seed':
                $this->seedSource();
                break;
            case 'verify':
                $this->verifyTarget();
                break;
            case 'write':
                $this->writeOnTarget();
                break;
            case 'rollback':
                $this->verifyRollback();
                break;
        }
    }

    private function seedSource(): void
    {
        $user = User::factory()->create(['email' => 'transfer-user@example.test']);
        $branch = Branch::factory()->create(['name' => 'Transfer branch']);
        $vacancy = Vacancy::factory()->create(['title' => 'Transfer vacancy', 'branch_id' => $branch->id, 'recruiter_id' => $user->id]);
        $candidate = Candidate::factory()->create(['full_name' => 'Transfer candidate', 'email' => 'transfer-candidate@example.test']);
        app(ApplicationService::class)->apply($user, $candidate, $vacancy);
        $employee = Employee::factory()->create([
            'full_name' => 'Марія 🙂', 'branch_id' => $branch->id, 'hired_at' => '2026-01-02',
            'work_schedule' => ['days' => ['пн', 'вт'], 'emoji' => '🙂'],
        ]);
        $body = $this->longBody();
        DB::table('document_templates')->insert([
            'name' => 'Transfer template', 'body' => $body,
            'created_at' => '2026-01-02 03:04:05', 'updated_at' => '2026-01-02 03:04:05',
        ]);
        $document = Document::query()->create(['employee_id' => $employee->id, 'title' => 'Transfer document', 'content_md' => $body]);
        app(DatabaseDocumentStorage::class)->put($document, $this->pdf(), 'transfer.pdf');
        $articleId = DB::table('kb_articles')->insertGetId([
            'title' => 'Transfer article', 'body_md' => $body, 'body_html' => '<p>'.htmlspecialchars($body, ENT_QUOTES, 'UTF-8').'</p>',
            'tags' => '["Україна","🙂"]', 'audience' => '{"type":"all"}',
        ]);
        DB::table('kb_article_versions')->insert(['article_id' => $articleId, 'version' => 1, 'title' => 'Transfer article', 'body_md' => $body]);
        $scriptId = DB::table('scripts')->insertGetId(['name' => 'Transfer script', 'channel' => 'call']);
        $versionId = DB::table('script_versions')->insertGetId([
            'script_id' => $scriptId, 'version' => 1, 'steps' => '[]', 'objections' => '[]',
            'templates' => '[]', 'followups' => '[]', 'next_step_patterns' => '{}',
        ]);
        DB::table('scripts')->where('id', $scriptId)->update(['active_version_id' => $versionId]);
        app(SecretVault::class)->put('transfer-proof', 'fixture', self::SECRET, $user->id);
        $this->assertSame(1, Application::query()->where('candidate_id', $candidate->id)->count());
        $this->assertGreaterThan(65535, strlen($body));
    }

    private function verifyTarget(): void
    {
        $source = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=app_transfer_source', 'app', 'app');
        $source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $source->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $candidate = Candidate::query()->where('email', 'transfer-candidate@example.test')->sole();
        $sourceCandidateId = $source->query("SELECT id FROM candidates WHERE email = 'transfer-candidate@example.test'")->fetchColumn();
        $this->assertSame((int) $sourceCandidateId, $candidate->id);
        $application = Application::query()->with(['vacancy.branch', 'stageChanges'])->where('candidate_id', $candidate->id)->sole();
        $this->assertSame('Transfer branch', $application->vacancy->branch->name);
        $this->assertCount(1, $application->stageChanges);

        $employee = Employee::query()->where('full_name', 'Марія 🙂')->sole();
        $this->assertSame('2026-01-02', $employee->hired_at->toDateString());
        $this->assertSame(['days' => ['пн', 'вт'], 'emoji' => '🙂'], $employee->work_schedule);
        $document = Document::query()->where('title', 'Transfer document')->sole();
        $this->assertSame($employee->id, $document->employee_id);
        $this->assertSame($this->longBody(), $document->content_md);
        $file = app(DatabaseDocumentStorage::class)->get($document);
        $this->assertNotNull($file);
        $this->assertSame(DocumentStorage::MAX_BYTES, $file->size);
        $this->assertSame($this->pdf(), $file->content);
        $sha = DB::table('documents_files')->where('document_id', $document->id)->value('sha256');
        $this->assertSame(hash('sha256', $file->content), $sha);
        $sourceSha = $source->query('SELECT sha256 FROM documents_files WHERE document_id = '.$document->id)->fetchColumn();
        $this->assertSame($sourceSha, $sha);

        $article = DB::table('kb_articles')->where('title', 'Transfer article')->sole();
        $this->assertSame($this->longBody(), $article->body_md);
        $this->assertStringContainsString('Марія', $article->body_html);
        $this->assertSame(['Україна', '🙂'], json_decode($article->tags, true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame($this->longBody(), DB::table('kb_article_versions')->where('article_id', $article->id)->value('body_md'));
        $script = DB::table('scripts')->where('name', 'Transfer script')->sole();
        $version = DB::table('script_versions')->where('script_id', $script->id)->sole();
        $this->assertSame($version->id, $script->active_version_id);
        $this->assertSame('2026-01-02 03:04:05', DB::table('document_templates')->where('name', 'Transfer template')->value('created_at'));
        $sourceCiphertext = $source->query("SELECT value FROM integration_secrets WHERE name = 'fixture'")->fetchColumn();
        $this->assertSame($sourceCiphertext, DB::table('integration_secrets')->where('name', 'fixture')->value('value'));
        $this->assertSame(self::SECRET, app(SecretVault::class)->get('transfer-proof', 'fixture'));
        $next = Candidate::factory()->create(['email' => 'sequence-check@example.test']);
        $this->assertGreaterThan($candidate->id, $next->id);
        $next->delete(); // Sequence probe is removed before the simulated cutover write.
    }

    private function writeOnTarget(): void
    {
        Candidate::factory()->create(['email' => 'after-cutover@example.test']);
        $this->assertSame(1, Candidate::query()->where('email', 'after-cutover@example.test')->count());
    }

    private function verifyRollback(): void
    {
        $replayed = Candidate::query()->where('email', 'after-cutover@example.test')->sole();
        $this->assertSame(0, Candidate::query()->where('email', 'sequence-check@example.test')->count());
        $this->assertSame(1, Candidate::query()->where('email', 'transfer-candidate@example.test')->count());
        $next = Candidate::factory()->create(['email' => 'after-rollback@example.test']);
        $this->assertGreaterThan($replayed->id, $next->id);
    }

    private function longBody(): string
    {
        return str_repeat('Марія Україна 🙂 ', 6000);
    }

    private function pdf(): string
    {
        return self::PDF.str_repeat(' ', DocumentStorage::MAX_BYTES - strlen(self::PDF));
    }
}
