<?php

declare(strict_types=1);

namespace Tests\Feature\Desk;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Desk\Models\DeskCategory;
use App\Modules\Knowledge\Models\KbArticle;
use App\Modules\Scripts\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\Support\NavBadgeAssertions;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/** Desk: authz matrix, thread with internal notes, SLA flags, the desk.sla job, attachments. Synthetic data only. */
final class DeskApiTest extends TestCase
{
    use NavBadgeAssertions, PeopleFixtures, RefreshDatabase;

    private const string PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $attributes */
    private function category(array $attributes = []): DeskCategory
    {
        return DeskCategory::query()->create($attributes + ['name' => 'Payroll questions', 'first_response_hours' => 4, 'resolve_hours' => 48, 'active' => true]);
    }

    public function test_authz_matrix(): void
    {
        $this->getJson('/api/desk/cases/mine')->assertUnauthorized();
        $this->getJson('/api/desk/cases')->assertUnauthorized();

        $org = $this->org();
        $category = $this->category();
        $worker = $this->userOf($org['worker']);
        $lead = $this->userOf($org['lead']);
        $admin = $this->login(UserRole::Admin);

        // Queue and categories management: HR admins only (a manager is not HR).
        $this->actingAs($lead)->getJson('/api/desk/cases')->assertForbidden();
        $this->actingAs($worker)->postJson('/api/desk/categories', ['name' => 'x'])->assertForbidden();
        $this->actingAs($admin)->postJson('/api/desk/categories', ['name' => 'Benefits', 'first_response_hours' => 8])->assertCreated();

        $id = $this->actingAs($worker)->postJson('/api/desk/cases', ['category_id' => $category->id, 'subject' => 'Payslip', 'body' => 'Where is my payslip?'])
            ->assertCreated()->assertJsonPath('data.status', 'new')->assertJsonPath('data.can_manage', false)->json('data.id');

        // Own case: visible to the requester and HR; the manager and a colleague get 404 (no existence leak).
        $this->actingAs($worker)->getJson("/api/desk/cases/$id")->assertOk();
        $this->actingAs($admin)->getJson("/api/desk/cases/$id")->assertOk()->assertJsonPath('data.can_manage', true);
        $this->actingAs($lead)->getJson("/api/desk/cases/$id")->assertNotFound();
        $this->actingAs($this->userOf($org['peer']))->getJson("/api/desk/cases/$id")->assertNotFound();
        $this->actingAs($this->userOf($org['peer']))->postJson("/api/desk/cases/$id/comments", ['body' => 'hi'])->assertNotFound();

        // The requester may only close; assigning or internal notes are HR actions.
        $this->actingAs($worker)->patchJson("/api/desk/cases/$id", ['assignee_id' => $admin->id])->assertForbidden();
        $this->actingAs($worker)->postJson("/api/desk/cases/$id/comments", ['body' => 'x', 'internal' => true])->assertForbidden();
        $this->actingAs($worker)->getJson('/api/desk/cases/mine')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($lead)->getJson('/api/desk/cases/mine')->assertOk()->assertJsonCount(0, 'data');

        // No employee record → no case.
        $this->actingAs($this->login())->postJson('/api/desk/cases', ['category_id' => $category->id, 'subject' => 'x', 'body' => 'y'])
            ->assertUnprocessable()->assertJsonPath('code', 'no_employee');
    }

    public function test_thread_hides_internal_notes_and_tracks_first_response(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        $category = $this->category();
        $user = $this->login();
        $this->employee(['full_name' => 'Case Owner'], $user);
        $admin = $this->login(UserRole::Admin);
        $article = KbArticle::query()->create([
            'title' => 'How payslips work', 'body_md' => 'x', 'body_html' => '<p>x</p>', 'tags' => [], 'audience' => ['type' => 'all'], 'status' => 'published',
        ]);
        $draft = KbArticle::query()->create([
            'title' => 'Draft', 'body_md' => 'x', 'body_html' => '<p>x</p>', 'tags' => [], 'audience' => ['type' => 'all'], 'status' => 'draft',
        ]);
        $id = $this->actingAs($user)->postJson('/api/desk/cases', ['category_id' => $category->id, 'subject' => 'Payslip', 'body' => 'Where?'])->json('data.id');

        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->actingAs($admin)->postJson("/api/desk/cases/$id/comments", ['body' => 'Checking with payroll', 'internal' => true])->assertCreated()
            ->assertJsonPath('data.first_response_at', null)->assertJsonPath('data.status', 'new');
        $this->actingAs($admin)->postJson("/api/desk/cases/$id/comments", ['body' => 'x', 'article_id' => $draft->id])
            ->assertUnprocessable()->assertJsonPath('code', 'article_not_found');
        $this->actingAs($admin)->postJson("/api/desk/cases/$id/comments", ['body' => 'See the article', 'article_id' => $article->id])->assertCreated()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.first_response_at', Carbon::parse('2026-10-05 10:00:00')->toIso8601String())
            ->assertJsonPath('data.sla.first_response_breached', false)
            ->assertJsonCount(2, 'data.comments');

        $employeeView = $this->actingAs($user)->getJson("/api/desk/cases/$id")->assertOk()->json('data');
        $this->assertCount(1, $employeeView['comments']);
        $this->assertSame('See the article', $employeeView['comments'][0]['body']);
        $this->assertSame(['id' => $article->id, 'title' => 'How payslips work'], $employeeView['comments'][0]['article']);
        $this->assertStringNotContainsString('Checking with payroll', (string) json_encode($employeeView));

        // Waiting → the requester answers → in progress; resolve / close / reopen move the clocks.
        $this->actingAs($admin)->patchJson("/api/desk/cases/$id", ['status' => 'waiting'])->assertOk();
        // Sidebar: the requester sees 1 case waiting for their answer; HR sees the open queue; the employee has no queue badge.
        $waiting = static fn (array $c): bool => $c['status'] === 'waiting';
        $this->assertBadgeMatchesList($user, 'desk_mine', '/api/desk/cases/mine', 1, 'data', $waiting);
        $this->assertBadgeMatchesList($admin, 'desk_queue', '/api/desk/cases?open=1', 1);
        $this->assertArrayNotHasKey('desk_queue', $this->badgesOf($user));
        $this->actingAs($user)->postJson("/api/desk/cases/$id/comments", ['body' => 'Thanks'])->assertCreated()->assertJsonPath('data.status', 'in_progress');
        $this->assertBadgeMatchesList($user, 'desk_mine', '/api/desk/cases/mine', 0, 'data', $waiting);
        $this->actingAs($admin)->patchJson("/api/desk/cases/$id", ['status' => 'resolved', 'assignee_id' => $admin->id])->assertOk()
            ->assertJsonPath('data.assignee.id', $admin->id)->assertJsonPath('data.resolved_at', Carbon::now()->toIso8601String());
        $this->actingAs($user)->patchJson("/api/desk/cases/$id", ['status' => 'closed'])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->actingAs($user)->postJson("/api/desk/cases/$id/comments", ['body' => 'again'])->assertStatus(409)->assertJsonPath('code', 'case_closed');
        $this->actingAs($admin)->patchJson("/api/desk/cases/$id", ['status' => 'in_progress'])->assertOk()
            ->assertJsonPath('data.resolved_at', null)->assertJsonPath('data.closed_at', null);

        // Assignee must be an HR user.
        $this->actingAs($admin)->patchJson("/api/desk/cases/$id", ['assignee_id' => $user->id])->assertUnprocessable()->assertJsonPath('code', 'invalid_assignee');
    }

    public function test_sla_breach_flags_and_job_is_idempotent(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        $admin = $this->login(UserRole::Admin);
        $category = $this->category(['first_response_hours' => 2, 'resolve_hours' => 24]);
        $user = $this->login();
        $employee = $this->employee([], $user);
        $id = $this->actingAs($user)->postJson('/api/desk/cases', ['category_id' => $category->id, 'subject' => 'Access card', 'body' => 'Lost it'])->json('data.id');

        Carbon::setTestNow('2026-10-05 12:00:00');
        $this->actingAs($admin)->getJson('/api/desk/cases?open=1')->assertOk()
            ->assertJsonPath('data.0.sla.first_response_breached', true)
            ->assertJsonPath('data.0.sla.resolve_breached', false)
            ->assertJsonPath('data.0.sla.first_response_due', Carbon::parse('2026-10-05 11:00:00')->toIso8601String());

        config(['ops.secret' => 'test-secret']);
        $run = fn (): array => $this->postJson('/api/ops/jobs/run', [], ['X-Ops-Secret' => 'test-secret'])->assertOk()->json('jobs')['desk.sla'];
        $this->assertSame(['ok' => true, 'sla_breaches' => 1, 'sla_unassigned' => 0], $run());
        $run();
        $task = Task::query()->where('type', 'desk_sla')->sole();
        $this->assertSame($admin->id, $task->assignee_id, 'no assignee → the first HR admin');
        $this->assertSame($employee->id, $task->employee_id);
        $this->assertSame('/desk/cases/'.$id, $task->link);

        // A day later the resolve target is breached too: one more task, still one per target.
        Carbon::setTestNow('2026-10-06 10:00:00');
        $run();
        $run();
        $this->assertSame(2, Task::query()->where('type', 'desk_sla')->count());

        // Resolved cases are not in the job any more.
        $this->actingAs($admin)->patchJson("/api/desk/cases/$id", ['status' => 'resolved'])->assertOk();
        $this->assertSame(0, $run()['sla_breaches']);
    }

    public function test_attachments_use_document_limits_and_download_as_attachment(): void
    {
        $category = $this->category();
        $user = $this->login();
        $this->employee([], $user);
        $id = $this->actingAs($user)->postJson('/api/desk/cases', ['category_id' => $category->id, 'subject' => 'Certificate', 'body' => 'Please'])->json('data.id');

        $this->actingAs($user)->post("/api/desk/cases/$id/attachments", ['file' => UploadedFile::fake()->createWithContent('fake.pdf', 'not a pdf at all')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_file');
        $this->actingAs($user)->post("/api/desk/cases/$id/attachments", ['file' => UploadedFile::fake()->create('big.pdf', 3000, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $data = $this->actingAs($user)->post("/api/desk/cases/$id/attachments", ['file' => UploadedFile::fake()->createWithContent('scan.pdf', self::PDF)], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.attachments.0');
        $this->assertSame('application/pdf', $data['mime']);

        $response = $this->actingAs($user)->get("/api/desk/cases/$id/attachments/{$data['id']}")->assertOk();
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame(self::PDF, $response->getContent());
        $this->actingAs($this->login())->get("/api/desk/cases/$id/attachments/{$data['id']}")->assertNotFound();
    }
}
