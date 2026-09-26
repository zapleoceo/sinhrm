<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Documents\Contracts\DocumentStorage;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Models\Signature;
use App\Modules\Scripts\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/**
 * Documents — every route: 401, documents-manage per role, 404 for unknown ids, 422 with string params, the exact
 * 2 MB file boundary, acknowledgement rules (only own + sent, archive closes the task, list filters cannot widen scope).
 */
final class DocumentsRoutesTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    /** @param  array<string, mixed>  $attributes */
    private function document(int $employeeId, array $attributes = []): Document
    {
        return Document::query()->create($attributes + ['employee_id' => $employeeId, 'title' => 'Sample document', 'status' => 'draft']);
    }

    private static function pdfOfSize(int $bytes): string
    {
        $head = "%PDF-1.4\n";
        $tail = "\n%%EOF\n";

        return $head.str_repeat('0', $bytes - strlen($head) - strlen($tail)).$tail;
    }

    /** @return iterable<string, array{string, string}> */
    public static function routes(): iterable
    {
        yield 'index' => ['GET', '/api/documents'];
        yield 'mine' => ['GET', '/api/me/documents'];
        yield 'show' => ['GET', '/api/documents/1'];
        yield 'file' => ['GET', '/api/documents/1/file'];
        yield 'acknowledge' => ['POST', '/api/documents/1/acknowledge'];
        yield 'reject' => ['POST', '/api/documents/1/reject'];
        yield 'templates' => ['GET', '/api/documents/templates'];
        yield 'templates.store' => ['POST', '/api/documents/templates'];
        yield 'templates.preview' => ['POST', '/api/documents/templates/preview'];
        yield 'templates.update' => ['PATCH', '/api/documents/templates/1'];
        yield 'store' => ['POST', '/api/documents'];
        yield 'update' => ['PATCH', '/api/documents/1'];
        yield 'upload' => ['POST', '/api/documents/1/file'];
        yield 'send' => ['POST', '/api/documents/1/send'];
    }

    #[DataProvider('routes')]
    public function test_guest_gets_401(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized();
    }

    /** @return iterable<string, array{UserRole, bool}> */
    public static function roles(): iterable
    {
        yield 'superadmin' => [UserRole::Superadmin, true];
        yield 'admin' => [UserRole::Admin, true];
        yield 'hr_manager' => [UserRole::HrManager, true];
        yield 'recruiter' => [UserRole::Recruiter, false];
        yield 'employee' => [UserRole::Employee, false];
        yield 'viewer' => [UserRole::Viewer, false];
    }

    #[DataProvider('roles')]
    public function test_manage_routes_per_role(UserRole $role, bool $allowed): void
    {
        $user = $this->login($role);
        $target = $this->employee([], $this->login(UserRole::Employee));
        $template = DocumentTemplate::query()->create(['name' => 'T', 'body' => 'Hi {ПІБ}']);
        $doc = $this->document($target->id, ['content_md' => 'Text']);

        $responses = [
            $this->actingAs($user)->getJson('/api/documents/templates'),
            $this->actingAs($user)->postJson('/api/documents/templates', ['name' => 'N', 'body' => 'B']),
            $this->actingAs($user)->postJson('/api/documents/templates/preview', ['body' => 'x']),
            $this->actingAs($user)->patchJson("/api/documents/templates/{$template->id}", ['name' => 'Renamed']),
            $this->actingAs($user)->postJson('/api/documents', ['employee_id' => $target->id, 'title' => 'New']),
            $this->actingAs($user)->patchJson("/api/documents/{$doc->id}", ['title' => 'Edited']),
            $this->actingAs($user)->post("/api/documents/{$doc->id}/file", ['file' => UploadedFile::fake()->createWithContent('a.pdf', self::pdfOfSize(100))], ['Accept' => 'application/json']),
            $this->actingAs($user)->postJson("/api/documents/{$doc->id}/send"),
        ];
        foreach ($responses as $response) {
            $allowed ? $response->assertSuccessful() : $response->assertForbidden();
        }
        // Non-admins without an employee card see no documents at all.
        $this->actingAs($user)->getJson('/api/documents')->assertOk()->assertJsonCount($allowed ? 2 : 0, 'data');
    }

    public function test_unknown_ids_are_404(): void
    {
        $admin = $this->login(UserRole::Admin);
        foreach (['GET /api/documents/999999', 'GET /api/documents/999999/file', 'PATCH /api/documents/999999', 'POST /api/documents/999999/send',
            'POST /api/documents/999999/acknowledge', 'POST /api/documents/999999/reject', 'PATCH /api/documents/templates/999999'] as $call) {
            [$method, $uri] = explode(' ', $call);
            $this->actingAs($admin)->json($method, $uri, ['title' => 'x', 'name' => 'x'])->assertNotFound();
        }
        // A document without a file.
        $doc = $this->document($this->employee()->id);
        $this->actingAs($admin)->get("/api/documents/{$doc->id}/file")->assertNotFound();
    }

    public function test_create_and_list_validation_with_strings(): void
    {
        $admin = $this->login(UserRole::Admin);
        $employee = $this->employee();
        $this->actingAs($admin)->postJson('/api/documents', ['employee_id' => (string) $employee->id, 'title' => 'String id'])->assertCreated()
            ->assertJsonPath('data.status', 'draft');
        $this->actingAs($admin)->postJson('/api/documents', ['employee_id' => $employee->id])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->actingAs($admin)->postJson('/api/documents', ['employee_id' => 999999, 'title' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $this->actingAs($admin)->postJson('/api/documents', ['employee_id' => $employee->id, 'template_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('template_id');
        $this->actingAs($admin)->postJson('/api/documents', ['employee_id' => 'abc', 'title' => 'x'])->assertUnprocessable();

        $this->actingAs($admin)->getJson("/api/documents?employee_id={$employee->id}&status=draft")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/documents?status=lost')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->actingAs($admin)->getJson('/api/documents?employee_id=x')->assertUnprocessable()->assertJsonValidationErrors('employee_id');
    }

    public function test_update_validation(): void
    {
        $admin = $this->login(UserRole::Admin);
        $doc = $this->document($this->employee()->id);
        $this->actingAs($admin)->patchJson("/api/documents/{$doc->id}", ['status' => 'signed'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->actingAs($admin)->patchJson("/api/documents/{$doc->id}", ['title' => ''])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->actingAs($admin)->postJson('/api/documents/templates', ['name' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->actingAs($admin)->postJson('/api/documents/templates/preview', [])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->actingAs($admin)->postJson('/api/documents/templates/preview', ['body' => 'x', 'employee_id' => 999999])->assertUnprocessable();
    }

    public function test_file_size_boundary_is_exactly_two_megabytes(): void
    {
        $admin = $this->login(UserRole::Admin);
        $doc = $this->document($this->employee()->id);
        $upload = fn (int $bytes) => $this->actingAs($admin)->post("/api/documents/{$doc->id}/file", [
            'file' => UploadedFile::fake()->createWithContent('scan.pdf', self::pdfOfSize($bytes)),
        ], ['Accept' => 'application/json']);

        $upload(DocumentStorage::MAX_BYTES + 1)->assertUnprocessable();
        $upload(DocumentStorage::MAX_BYTES)->assertOk()->assertJsonPath('data.file.size', DocumentStorage::MAX_BYTES);
        $this->actingAs($admin)->post("/api/documents/{$doc->id}/file", [], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        // After sending, the file is frozen.
        $doc->employee->update(['user_id' => $this->login(UserRole::Employee)->id]);
        $this->actingAs($admin)->postJson("/api/documents/{$doc->id}/send")->assertOk();
        $upload(100)->assertStatus(409)->assertJsonPath('code', 'not_editable');
    }

    public function test_acknowledge_rules(): void
    {
        $admin = $this->login(UserRole::Admin);
        $user = $this->login(UserRole::Employee);
        $employee = $this->employee([], $user);
        $doc = $this->document($employee->id, ['content_md' => 'Rules']);
        $this->actingAs($user)->postJson("/api/documents/{$doc->id}/reject")->assertNotFound(); // draft: invisible to the employee
        $this->actingAs($admin)->postJson("/api/documents/{$doc->id}/send")->assertOk();
        $this->actingAs($admin)->postJson("/api/documents/{$doc->id}/send")->assertStatus(409)->assertJsonPath('code', 'not_editable');
        $this->actingAs($user)->postJson("/api/documents/{$doc->id}/reject", ['reason' => str_repeat('r', 501)])->assertUnprocessable()->assertJsonValidationErrors('reason');

        // Archiving a sent document closes the acknowledgement task; the employee can no longer acknowledge.
        $this->actingAs($admin)->patchJson("/api/documents/{$doc->id}", ['status' => 'archived'])->assertOk();
        $this->assertNotNull(Task::query()->sole()->done_at);
        $this->actingAs($user)->postJson("/api/documents/{$doc->id}/acknowledge")->assertStatus(409)->assertJsonPath('code', 'not_sent');
        $this->actingAs($admin)->postJson("/api/documents/{$doc->id}/send")->assertStatus(409);
        $this->assertSame(0, Signature::query()->count());
    }

    public function test_signed_document_rejects_edit_and_reject(): void
    {
        $admin = $this->login(UserRole::Admin);
        $user = $this->login(UserRole::Employee);
        $doc = $this->document($this->employee([], $user)->id, ['content_md' => 'Rules']);
        $this->actingAs($admin)->postJson("/api/documents/{$doc->id}/send")->assertOk();
        $this->actingAs($user)->postJson("/api/documents/{$doc->id}/acknowledge")->assertOk()->assertJsonPath('data.status', 'signed');

        $this->actingAs($user)->postJson("/api/documents/{$doc->id}/reject", ['reason' => 'changed my mind'])->assertStatus(409)->assertJsonPath('code', 'already_signed');
        $this->actingAs($admin)->patchJson("/api/documents/{$doc->id}", ['content_md' => 'rewritten'])->assertStatus(409)->assertJsonPath('code', 'not_editable');
        $this->assertSame('Rules', $doc->fresh()?->content_md);
    }

    public function test_list_filter_cannot_widen_the_scope(): void
    {
        $org = $this->org();
        $this->document($org['other']->id, ['status' => 'sent']);
        $this->document($org['worker']->id, ['status' => 'draft']);
        $worker = $this->userOf($org['worker']);

        $this->actingAs($worker)->getJson("/api/documents?employee_id={$org['other']->id}")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($worker)->getJson('/api/documents?status=draft')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->userOf($org['lead']))->getJson("/api/documents?employee_id={$org['other']->id}")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_templates_listing_hides_archived_unless_asked(): void
    {
        $admin = $this->login(UserRole::Admin);
        DocumentTemplate::query()->create(['name' => 'Live', 'body' => 'x']);
        DocumentTemplate::query()->create(['name' => 'Old', 'body' => 'x', 'archived' => true]);
        $this->actingAs($admin)->getJson('/api/documents/templates?archived=0')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/documents/templates?archived=1')->assertOk()->assertJsonCount(2, 'data');
    }
}
