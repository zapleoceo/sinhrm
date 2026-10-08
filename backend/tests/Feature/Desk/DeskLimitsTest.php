<?php

declare(strict_types=1);

namespace Tests\Feature\Desk;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Desk\Enums\CaseStatus;
use App\Modules\Desk\Models\DeskAttachment;
use App\Modules\Desk\Models\DeskCase;
use App\Modules\Desk\Models\DeskCategory;
use App\Modules\Desk\Services\DeskService;
use App\Modules\People\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/**
 * Desk abuse limits: the write endpoints are throttled, an employee may not hold an unbounded number of open
 * cases, and the base64 attachments of one case may not grow past the per-case byte quota. Synthetic data only.
 */
final class DeskLimitsTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private const string PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    /** @param  array<string, mixed>  $attributes */
    private function category(array $attributes = []): DeskCategory
    {
        return DeskCategory::query()->create($attributes + ['name' => 'Access cards', 'first_response_hours' => 4, 'resolve_hours' => 48, 'active' => true]);
    }

    /** @return array{User, Employee} */
    private function requester(): array
    {
        $user = $this->login(UserRole::Employee);

        return [$user, $this->employee([], $user)];
    }

    public function test_opening_cases_is_throttled(): void
    {
        [$user] = $this->requester();
        $category = $this->category();
        $open = fn () => $this->actingAs($user)->postJson('/api/desk/cases', ['category_id' => $category->id, 'subject' => 'Card', 'body' => 'Broken']);

        for ($i = 0; $i < 20; $i++) {
            $open()->assertCreated();
        }

        $open()->assertStatus(429);
    }

    /** Desk has its own bucket (named limiter desk-write): other "throttle:20,1" routes do not eat into it. */
    public function test_desk_bucket_is_not_shared_with_other_throttled_routes(): void
    {
        Route::post('/api/_test/shared-throttle', static fn () => response()->noContent())->middleware('throttle:20,1');
        [$user] = $this->requester();
        $category = $this->category();

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($user)->postJson('/api/_test/shared-throttle')->assertNoContent();
        }
        $this->actingAs($user)->postJson('/api/_test/shared-throttle')->assertStatus(429);

        // The generic bucket is exhausted, Desk still accepts the user's case.
        $this->actingAs($user)->postJson('/api/desk/cases', ['category_id' => $category->id, 'subject' => 'Card', 'body' => 'Broken'])
            ->assertCreated();
    }

    public function test_commenting_is_throttled(): void
    {
        [$user] = $this->requester();
        $case = $this->seedCase($user);
        $comment = fn () => $this->actingAs($user)->postJson("/api/desk/cases/{$case->id}/comments", ['body' => 'ping']);

        for ($i = 0; $i < 20; $i++) {
            $comment()->assertCreated();
        }

        $comment()->assertStatus(429);
    }

    public function test_attaching_is_throttled(): void
    {
        [$user] = $this->requester();
        $case = $this->seedCase($user);
        $attach = fn () => $this->actingAs($user)->post("/api/desk/cases/{$case->id}/attachments", [
            'file' => UploadedFile::fake()->createWithContent('scan.pdf', self::PDF),
        ], ['Accept' => 'application/json']);

        for ($i = 0; $i < 10; $i++) {
            $attach()->assertCreated();
        }
        // Past the per-case file count the request is refused, but it still costs a throttle hit.
        for ($i = 0; $i < 10; $i++) {
            $attach()->assertUnprocessable()->assertJsonPath('code', 'too_many_files');
        }

        $attach()->assertStatus(429);
    }

    public function test_open_cases_per_employee_are_capped(): void
    {
        [$user, $employee] = $this->requester();
        $category = $this->category();
        for ($i = 0; $i < DeskService::MAX_OPEN_CASES; $i++) {
            $this->seedCase($user, $employee, $category);
        }

        $this->actingAs($user)->postJson('/api/desk/cases', ['category_id' => $category->id, 'subject' => 'One more', 'body' => 'x'])
            ->assertUnprocessable()->assertJsonPath('code', 'too_many_open_cases');

        // Closing one frees a slot.
        DeskCase::query()->where('employee_id', $employee->id)->limit(1)->update(['status' => CaseStatus::Closed->value]);
        $this->actingAs($user)->postJson('/api/desk/cases', ['category_id' => $category->id, 'subject' => 'One more', 'body' => 'x'])->assertCreated();
    }

    public function test_attachment_bytes_per_case_are_capped(): void
    {
        [$user] = $this->requester();
        $case = $this->seedCase($user);
        DeskAttachment::query()->create([
            'case_id' => $case->id, 'uploaded_by' => $user->id, 'filename' => 'big.pdf', 'mime' => 'application/pdf',
            'size' => DeskService::MAX_CASE_BYTES - 10, 'sha256' => hash('sha256', 'x'), 'content' => base64_encode('x'),
        ]);

        $this->actingAs($user)->post("/api/desk/cases/{$case->id}/attachments", [
            'file' => UploadedFile::fake()->createWithContent('scan.pdf', self::PDF),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonPath('code', 'attachment_quota_exceeded');
    }

    private function seedCase(User $user, ?Employee $employee = null, ?DeskCategory $category = null): DeskCase
    {
        $employee ??= Employee::query()->where('user_id', $user->id)->sole();

        return DeskCase::query()->create([
            'employee_id' => $employee->id,
            'category_id' => ($category ?? $this->category())->id,
            'subject' => 'Card',
            'body' => 'Broken',
            'status' => CaseStatus::New->value,
            'created_by' => $user->id,
        ]);
    }
}
