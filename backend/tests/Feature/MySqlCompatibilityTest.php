<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\GoogleWorkspace\Models\SheetImport;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Recruiting\Repositories\EloquentCandidateRepository;
use App\Modules\Recruiting\Repositories\EloquentTouchpointRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/** Cross-driver regressions using real tables; the MySQL CI job runs these on MySQL 8.4. */
final class MySqlCompatibilityTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    public function test_opaque_identifiers_and_profile_urls_remain_case_sensitive(): void
    {
        $first = Candidate::factory()->create();
        $second = Candidate::factory()->create();
        $touchpoints = new EloquentTouchpointRepository;
        $candidates = new EloquentCandidateRepository;

        $upper = Touchpoint::query()->create([
            'channel' => Channel::Telegram, 'external_id' => 'Message-A', 'occurred_at' => now(),
        ]);
        $lower = Touchpoint::query()->create([
            'channel' => Channel::Telegram, 'external_id' => 'Message-a', 'occurred_at' => now(),
        ]);
        $this->assertNotSame($upper->id, $lower->id);
        $this->assertSame($upper->id, $touchpoints->findByExternalId(Channel::Telegram, 'Message-A')?->id);
        $this->assertSame($lower->id, $touchpoints->findByExternalId(Channel::Telegram, 'Message-a')?->id);

        $this->assertTrue($candidates->attachProfileUrl($first->id, 'workua', 'https://example.test/Profile/A'));
        $this->assertTrue($candidates->attachProfileUrl($second->id, 'workua', 'https://example.test/Profile/a'));
        $this->assertSame($first->id, $candidates->findByProfileUrl('https://example.test/Profile/A')?->id);
        $this->assertSame($second->id, $candidates->findByProfileUrl('https://example.test/Profile/a')?->id);

        $upperSheet = SheetImport::query()->create(['spreadsheet_id' => 'Sheet-A', 'headers' => [], 'mapping' => []]);
        $lowerSheet = SheetImport::query()->create(['spreadsheet_id' => 'Sheet-a', 'headers' => [], 'mapping' => []]);
        $this->assertSame($upperSheet->id, SheetImport::query()->where('spreadsheet_id', 'Sheet-A')->value('id'));
        $this->assertSame($lowerSheet->id, SheetImport::query()->where('spreadsheet_id', 'Sheet-a')->value('id'));
    }

    public function test_large_unicode_documents_and_knowledge_history_roundtrip(): void
    {
        // 48,000 Unicode characters exceed MySQL TEXT's 65,535-byte limit.
        $body = str_repeat('Україна🙂', 6000);
        $this->assertGreaterThan(65535, strlen($body));
        $employee = $this->employee();
        $templateId = DB::table('document_templates')->insertGetId(['name' => 'Unicode', 'body' => $body]);
        $documentId = DB::table('documents')->insertGetId([
            'employee_id' => $employee->id, 'title' => 'Unicode', 'content_md' => $body,
        ]);
        $rendered = '<p>'.htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>';
        $articleId = DB::table('kb_articles')->insertGetId([
            'title' => 'Unicode', 'body_md' => $body, 'body_html' => $rendered,
            'tags' => '[]', 'audience' => '{"type":"all"}',
        ]);
        DB::table('kb_article_versions')->insert([
            'article_id' => $articleId, 'version' => 1, 'title' => 'Unicode', 'body_md' => $body,
        ]);

        $this->assertSame($body, DB::table('document_templates')->where('id', $templateId)->value('body'));
        $this->assertSame($body, DB::table('documents')->where('id', $documentId)->value('content_md'));
        $this->assertSame($body, DB::table('kb_articles')->where('id', $articleId)->value('body_md'));
        $this->assertSame($rendered, DB::table('kb_articles')->where('id', $articleId)->value('body_html'));
        $this->assertSame($body, DB::table('kb_article_versions')->where('article_id', $articleId)->value('body_md'));
    }

    public function test_database_cache_session_and_queue_tables_accept_runtime_writes(): void
    {
        Cache::store('database')->put('mysql-compatibility', 'persisted', 60);
        $this->assertSame('persisted', Cache::store('database')->get('mysql-compatibility'));

        $session = app('session')->driver('database');
        $session->start();
        $session->put('mysql-compatibility', 'persisted');
        $session->save();
        $this->assertTrue(DB::table('sessions')->where('id', $session->getId())->exists());

        Queue::connection('database')->pushRaw('{"job":"compatibility"}');
        $this->assertTrue(DB::table('jobs')->where('payload', '{"job":"compatibility"}')->exists());
    }
}
