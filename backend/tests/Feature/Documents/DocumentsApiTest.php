<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Position;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Models\Signature;
use App\Modules\Scripts\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\Support\NavBadgeAssertions;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/** Documents: templates, rendering & sanitization, access, send → acknowledge, files. Synthetic data only. */
final class DocumentsApiTest extends TestCase
{
    use NavBadgeAssertions, PeopleFixtures, RefreshDatabase;

    private const string PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_guest_401_and_writes_admin_only(): void
    {
        $this->getJson('/api/documents')->assertUnauthorized();
        $this->getJson('/api/me/documents')->assertUnauthorized();
        $this->getJson('/api/documents/templates')->assertUnauthorized();

        $org = $this->org();
        $manager = $this->userOf($org['lead']);
        $this->actingAs($manager)->getJson('/api/documents/templates')->assertForbidden();
        $this->actingAs($manager)->postJson('/api/documents/templates', ['name' => 'x', 'body' => 'y'])->assertForbidden();
        $this->actingAs($manager)->postJson('/api/documents', ['employee_id' => $org['worker']->id, 'title' => 'x'])->assertForbidden();
        $this->actingAs($this->login(UserRole::Viewer))->postJson('/api/documents/templates/preview', ['body' => 'x'])->assertForbidden();
    }

    public function test_templates_reject_unknown_variables_and_archive(): void
    {
        $admin = $this->login(UserRole::Admin);

        $this->actingAs($admin)->postJson('/api/documents/templates', ['name' => 'Offer', 'body' => 'Hi {Імя} and {ПІБ}'])
            ->assertUnprocessable()->assertJsonPath('code', 'unknown_variables')->assertJsonPath('variables', ['Імя']);
        $id = $this->actingAs($admin)->postJson('/api/documents/templates', ['name' => 'Offer', 'category' => 'contracts', 'body' => 'Hi {ПІБ}'])
            ->assertCreated()->assertJsonPath('data.category', 'contracts')->json('data.id');
        $this->actingAs($admin)->patchJson("/api/documents/templates/$id", ['body' => '{Nope}'])->assertUnprocessable();
        $this->actingAs($admin)->patchJson("/api/documents/templates/$id", ['archived' => true])->assertOk()->assertJsonPath('data.archived', true);
        $this->actingAs($admin)->getJson('/api/documents/templates')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($admin)->getJson('/api/documents/templates?archived=1')->assertOk()->assertJsonCount(1, 'data');
        $employee = $this->employee();
        $this->actingAs($admin)->postJson('/api/documents', ['employee_id' => $employee->id, 'template_id' => $id])
            ->assertUnprocessable()->assertJsonPath('code', 'template_archived');
    }

    public function test_preview_fills_variables_and_escapes_html(): void
    {
        $admin = $this->login(UserRole::Admin);
        $body = "# Наказ\n\n{ПІБ}, {Посада}, {Сьогодні}\n\n<script>alert(1)</script>\n\n[click](javascript:alert(2)) <img src=x onerror=alert(3)>";

        $data = $this->actingAs($admin)->postJson('/api/documents/templates/preview', ['body' => $body])->assertOk()->json('data');
        $this->assertIsArray($data);
        $html = (string) $data['html'];
        $this->assertStringContainsString('<h1>Наказ</h1>', $html);
        $this->assertStringContainsString('Олена Приклад, Адміністратор', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<img', $html);

        // With a real employee: missing values are listed and shown as "—"; unknown tokens are reported.
        $employee = $this->employee(['full_name' => 'Petro Test']);
        $this->actingAs($admin)->postJson('/api/documents/templates/preview', ['body' => '{ПІБ} {Посада} {Bad}', 'employee_id' => $employee->id])->assertOk()
            ->assertJsonPath('data.markdown', 'Petro Test — {Bad}')
            ->assertJsonPath('data.missing', ['Посада'])
            ->assertJsonPath('data.unknown', ['Bad']);
    }

    public function test_generate_from_template_freezes_the_filled_text(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        $admin = $this->login(UserRole::Admin);
        $org = $this->org();
        $position = Position::factory()->create(['name' => 'Methodist']);
        $org['worker']->update(['position_id' => $position->id, 'hired_at' => '2026-09-01']);
        $template = DocumentTemplate::query()->create(['name' => 'Order', 'category' => 'orders', 'body' => "{Ім'я} / {Посада} / {Дата прийому} / {Керівник} / {Сьогодні}"]);

        $doc = $this->actingAs($admin)->postJson('/api/documents', ['employee_id' => $org['worker']->id, 'template_id' => $template->id])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Order')
            ->assertJsonPath('data.category', 'orders')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.content_md', 'Worker / Methodist / 01.09.2026 / Lead Person / 05.10.2026')
            ->json('data');
        $this->assertIsArray($doc);
        $template->update(['body' => 'changed']);
        $this->actingAs($admin)->getJson("/api/documents/{$doc['id']}")->assertOk()
            ->assertJsonPath('data.content_md', 'Worker / Methodist / 01.09.2026 / Lead Person / 05.10.2026');

        $this->actingAs($admin)->postJson('/api/documents', ['employee_id' => $org['worker']->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['title']);
    }

    public function test_access_matrix_and_drafts_hidden_from_non_admins(): void
    {
        $admin = $this->login(UserRole::Admin);
        $org = $this->org();
        $draft = $this->document($org['worker']->id, ['status' => 'draft']);
        $sent = $this->document($org['worker']->id, ['status' => 'sent', 'content_md' => 'Text']);
        $othersDoc = $this->document($org['other']->id, ['status' => 'sent']);

        $this->actingAs($admin)->getJson('/api/documents')->assertOk()->assertJsonCount(3, 'data');
        $this->actingAs($admin)->getJson('/api/documents?employee_id='.$org['worker']->id.'&status=draft')->assertOk()->assertJsonCount(1, 'data');

        $worker = $this->userOf($org['worker']);
        $this->actingAs($worker)->getJson('/api/me/documents')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $sent->id)->assertJsonPath('data.0.can_acknowledge', true);
        // Sidebar: documents I can acknowledge (the draft and the other employee's document do not count).
        $ack = static fn (array $d): bool => $d['can_acknowledge'] === true;
        $this->assertBadgeMatchesList($worker, 'my_documents', '/api/me/documents', 1, 'data', $ack);
        $this->assertBadgeMatchesList($this->userOf($org['lead']), 'my_documents', '/api/me/documents', 0, 'data', $ack);
        $this->actingAs($worker)->getJson("/api/documents/{$sent->id}")->assertOk()
            ->assertJsonPath('data.content_md', null)
            ->assertJsonPath('data.html', "<p>Text</p>\n");
        $this->actingAs($worker)->getJson("/api/documents/{$draft->id}")->assertNotFound();
        $this->actingAs($worker)->getJson("/api/documents/{$othersDoc->id}")->assertNotFound();

        foreach (['lead', 'head'] as $manager) {
            $user = $this->userOf($org[$manager]);
            $this->actingAs($user)->getJson('/api/documents')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_acknowledge', false);
            $this->actingAs($user)->getJson("/api/documents/{$sent->id}")->assertOk();
            $this->actingAs($user)->postJson("/api/documents/{$sent->id}/acknowledge")->assertNotFound();
        }
        foreach (['peer', 'other'] as $outsider) {
            $this->actingAs($this->userOf($org[$outsider]))->getJson("/api/documents/{$sent->id}")->assertNotFound();
        }
        $this->actingAs($this->login(UserRole::Viewer))->getJson('/api/me/documents')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_send_acknowledge_flow_with_task_and_hashed_client(): void
    {
        $admin = $this->login(UserRole::Admin);
        $org = $this->org();
        $worker = $this->userOf($org['worker']);
        $id = $this->actingAs($admin)->postJson('/api/documents', ['employee_id' => $org['worker']->id, 'title' => 'Safety rules'])->json('data.id');

        $this->actingAs($admin)->postJson("/api/documents/$id/send")->assertUnprocessable()->assertJsonPath('code', 'empty_document');
        $this->actingAs($admin)->patchJson("/api/documents/$id", ['content_md' => '**Read me**'])->assertOk();
        $this->actingAs($worker)->postJson("/api/documents/$id/acknowledge")->assertNotFound();
        $this->actingAs($admin)->postJson("/api/documents/$id/send")->assertOk()->assertJsonPath('data.status', 'sent');
        $this->actingAs($admin)->patchJson("/api/documents/$id", ['content_md' => 'changed'])->assertStatus(409)->assertJsonPath('code', 'not_editable');

        $task = Task::query()->sole();
        $this->assertSame($worker->id, $task->assignee_id);
        $this->assertSame('document', $task->type->value);
        $this->assertSame('/me/documents', $task->link);
        $this->actingAs($worker)->getJson('/api/tasks?mine=1&source=documents')->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($this->userOf($org['lead']))->postJson("/api/documents/$id/acknowledge")->assertNotFound();
        $this->actingAs($admin)->postJson("/api/documents/$id/acknowledge")->assertNotFound();
        $response = $this->actingAs($worker)->withHeaders(['User-Agent' => 'SyntheticBrowser/1.0'])
            ->postJson("/api/documents/$id/acknowledge")->assertOk()
            ->assertJsonPath('data.status', 'signed')
            ->assertJsonPath('data.signatures.0.method', 'manual_ack')
            ->assertJsonPath('data.signatures.0.signer_employee_id', $org['worker']->id)
            ->assertJsonPath('data.can_acknowledge', false);
        $this->assertStringNotContainsString('ip_hash', (string) $response->getContent());
        $signature = Signature::query()->sole();
        $this->assertSame(hash_hmac('sha256', 'SyntheticBrowser/1.0', (string) config('app.key')), $signature->user_agent_hash);
        $this->assertSame(64, strlen((string) $signature->ip_hash));
        $this->assertNotSame('127.0.0.1', $signature->ip_hash);
        $this->assertNotNull($task->refresh()->done_at);

        $this->actingAs($worker)->postJson("/api/documents/$id/acknowledge")->assertStatus(409)->assertJsonPath('code', 'already_signed');
        $this->actingAs($admin)->patchJson("/api/documents/$id", ['status' => 'archived'])->assertOk()->assertJsonPath('data.status', 'archived');
    }

    public function test_reject_and_resend_reopens_the_same_task(): void
    {
        $admin = $this->login(UserRole::Admin);
        $user = $this->login(UserRole::Viewer);
        $employee = $this->employee([], $user);
        $doc = $this->document($employee->id, ['content_md' => 'Text']);
        $this->actingAs($admin)->postJson("/api/documents/{$doc->id}/send")->assertOk();

        $this->actingAs($user)->postJson("/api/documents/{$doc->id}/reject", ['reason' => 'Wrong date'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.reject_reason', 'Wrong date');
        $this->assertNotNull(Task::query()->sole()->done_at);
        $this->actingAs($user)->postJson("/api/documents/{$doc->id}/acknowledge")->assertStatus(409)->assertJsonPath('code', 'not_sent');
        $this->actingAs($admin)->patchJson("/api/documents/{$doc->id}", ['content_md' => 'Fixed'])->assertOk();
        $this->actingAs($admin)->postJson("/api/documents/{$doc->id}/send")->assertOk()->assertJsonPath('data.reject_reason', null);
        $this->assertNull(Task::query()->sole()->done_at);
    }

    public function test_send_requires_an_employee_login(): void
    {
        $admin = $this->login(UserRole::Admin);
        $doc = $this->document($this->employee()->id, ['content_md' => 'Text']);

        $this->actingAs($admin)->postJson("/api/documents/{$doc->id}/send")->assertUnprocessable()->assertJsonPath('code', 'employee_has_no_login');
    }

    public function test_file_upload_validates_type_and_size_and_downloads_as_attachment(): void
    {
        $admin = $this->login(UserRole::Admin);
        $org = $this->org();
        $doc = $this->document($org['worker']->id);

        $this->actingAs($admin)->post("/api/documents/{$doc->id}/file", ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'plain text')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        // Right extension, wrong bytes: refused by the storage (type detected from the content).
        $this->actingAs($admin)->post("/api/documents/{$doc->id}/file", ['file' => UploadedFile::fake()->createWithContent('fake.pdf', '<html><script>x</script></html>')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_file');
        $this->actingAs($admin)->post("/api/documents/{$doc->id}/file", ['file' => UploadedFile::fake()->create('big.pdf', 2049, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->actingAs($admin)->post("/api/documents/{$doc->id}/file", ['file' => UploadedFile::fake()->createWithContent('Contract "A".pdf', self::PDF)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.file.mime', 'application/pdf')->assertJsonPath('data.file.size', strlen(self::PDF));

        $download = $this->actingAs($admin)->get("/api/documents/{$doc->id}/file")->assertOk();
        $this->assertSame(self::PDF, $download->getContent());
        $this->assertSame('application/pdf', $download->headers->get('Content-Type'));
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('attachment;', (string) $download->headers->get('Content-Disposition'));
        // The worker does not see the draft (nor its file).
        $this->actingAs($this->userOf($org['worker']))->get("/api/documents/{$doc->id}/file")->assertNotFound();
        $this->assertStringNotContainsString(base64_encode(self::PDF), (string) $this->actingAs($admin)->getJson("/api/documents/{$doc->id}")->getContent());
    }

    /** @param  array<string, mixed>  $attributes */
    private function document(int $employeeId, array $attributes = []): Document
    {
        return Document::query()->create($attributes + ['employee_id' => $employeeId, 'title' => 'Sample document', 'status' => 'draft']);
    }
}
