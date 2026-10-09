<?php

declare(strict_types=1);

namespace Tests\Unit\Audit;

use App\Modules\Audit\Contracts\AuditLogRepository;
use App\Modules\Audit\DTO\AuditRecord;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditService;
use App\Modules\Audit\Support\AuditContextStack;
use App\Modules\Audit\Support\AuditPolicy;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/** HRM-28: AuditLogger::recordDiff (only changed fields, masked) and AuditContext (meta.bulk on every row inside). */
final class AuditDiffAndContextTest extends TestCase
{
    /** @var list<AuditRecord> */
    private array $stored = [];

    private AuditContextStack $context;

    private AuditService $service;

    protected function setUp(): void
    {
        $repository = $this->createMock(AuditLogRepository::class);
        $repository->method('store')->willReturnCallback(function (AuditRecord $record): void {
            $this->stored[] = $record;
        });
        $guard = $this->createStub(Guard::class);
        $guard->method('id')->willReturn(null);
        $guard->method('user')->willReturn(null);
        $auth = $this->createStub(AuthFactory::class);
        $auth->method('guard')->willReturn($guard);
        $db = $this->createStub(DatabaseManager::class);
        $db->method('__call')->willReturnCallback(static function (string $method, array $args): mixed {
            return $method === 'afterCommit' ? $args[0]() : null;
        });
        $this->context = new AuditContextStack;
        $this->service = new AuditService($repository, new AuditPolicy, $auth, $db, new NullLogger, $this->context);
    }

    public function test_diff_keeps_only_changed_fields_and_masks_the_salary(): void
    {
        $before = ['id' => 7, 'status' => 'draft', 'salary' => '30000 UAH', 'sent_at' => null, 'template_id' => 3, 'updated_at' => '2026-10-01 10:00:00'];
        $after = ['id' => 7, 'status' => 'sent', 'salary' => '30000 UAH', 'sent_at' => Carbon::parse('2026-10-02 09:00:00'), 'template_id' => '3', 'updated_at' => '2026-10-02 09:00:00'];

        $this->service->recordDiff('offer', 7, AuditAction::StatusChanged, $before, $after);

        $this->assertCount(1, $this->stored);
        $changes = $this->stored[0]->changes ?? [];
        // template_id 3 vs "3" is no change; salary unchanged; updated_at is noise.
        $this->assertSame(['status', 'sent_at'], array_keys($changes));
        $this->assertSame(['from' => 'draft', 'to' => 'sent'], $changes['status']);
        $this->assertSame(AuditAction::StatusChanged, $this->stored[0]->action);
    }

    public function test_created_diff_masks_everything_outside_the_allow_list(): void
    {
        $this->service->recordDiff('offer', 1, AuditAction::Created, [], ['salary' => '30000 UAH', 'position' => 'Manager', 'conditions' => null, 'status' => 'draft']);

        $changes = $this->stored[0]->changes ?? [];
        $this->assertSame(['from' => null, 'to' => '***'], $changes['salary']);
        $this->assertSame(['from' => null, 'to' => '***'], $changes['position']);
        $this->assertArrayNotHasKey('conditions', $changes); // null → null is no change
        $this->assertSame('draft', $changes['status']['to']);
        $this->assertStringNotContainsString('30000', (string) json_encode($this->stored));
    }

    public function test_nothing_changed_writes_no_row(): void
    {
        $row = ['status' => 'draft', 'hours' => '8.00', 'updated_at' => '2026-10-01'];
        $this->service->recordDiff('timesheet', 1, AuditAction::StatusChanged, $row, [...$row, 'updated_at' => '2026-10-02']);
        $this->service->recordDiff('timesheet', 1, AuditAction::Updated, $row, $row);

        $this->assertSame([], $this->stored);
    }

    public function test_context_meta_lands_on_every_row_inside_and_explicit_meta_wins(): void
    {
        $this->context->within(['bulk' => 'candidates.move'], function (): void {
            $this->service->record('application', 1, AuditAction::StageChanged, ['stage_id' => ['from' => 1, 'to' => 2]], ['candidate_id' => 5]);
            $this->service->record('application', 2, AuditAction::StageChanged, ['stage_id' => ['from' => 1, 'to' => 2]], ['bulk' => 'explicit']);
        });
        $this->service->record('application', 3, AuditAction::StageChanged, ['stage_id' => ['from' => 1, 'to' => 2]]);

        $this->assertSame(['bulk' => 'candidates.move', 'candidate_id' => 5], $this->stored[0]->meta);
        $this->assertSame(['bulk' => 'explicit'], $this->stored[1]->meta);
        $this->assertNull($this->stored[2]->meta);
    }

    public function test_context_is_popped_even_when_the_item_fails_and_nests(): void
    {
        try {
            $this->context->within(['bulk' => 'people.update'], static fn (): never => throw new RuntimeException('item failed'));
        } catch (RuntimeException) {
            // one failed item must not mark the next rows
        }
        $this->assertSame([], $this->context->current());

        $inner = $this->context->within(['bulk' => 'a', 'x' => 1], fn (): array => $this->context->within(['bulk' => 'b'], fn (): array => $this->context->current()));
        $this->assertSame(['bulk' => 'b', 'x' => 1], $inner);
        $this->assertSame([], $this->context->current());
    }
}
