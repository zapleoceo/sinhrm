<?php

declare(strict_types=1);

namespace Tests\Unit\Privacy;

use App\Modules\Core\DTO\DataSubject;
use App\Modules\Core\Enums\DataSubjectType;
use App\Modules\Privacy\Contracts\PrivacyRepository;
use App\Modules\Privacy\Models\PrivacyRequest;
use App\Modules\Privacy\Repositories\EloquentPrivacyRepository;
use App\Modules\Privacy\Services\PersonalDataService;
use App\Modules\Privacy\Services\RetentionJob;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Privacy's journal and settings go through PrivacyRepository: the binding and the delegation (no DB). */
final class PrivacyRepositoryTest extends TestCase
{
    public function test_the_contract_is_bound_to_the_eloquent_repository(): void
    {
        $this->assertInstanceOf(EloquentPrivacyRepository::class, $this->app->make(PrivacyRepository::class));
    }

    public function test_export_is_journaled_through_the_repository(): void
    {
        $fake = new FakePrivacyRepository;
        $service = new PersonalDataService([], $fake);

        $export = $service->export(new DataSubject(DataSubjectType::Candidate, 5), 9);

        $this->assertSame(['type' => 'candidate', 'id' => 5], $export['subject']);
        $this->assertSame([['candidate', 5, 'export', 'manual', null, 9, null]], $fake->journal);
    }

    public function test_retention_is_off_when_the_repository_has_no_rule(): void
    {
        $fake = new FakePrivacyRepository;
        $job = new RetentionJob([], new PersonalDataService([], $fake), $fake);

        $this->assertSame(['enabled' => false], $job->run(Carbon::now()));

        $fake->months = 6;
        $this->assertSame(['enabled' => true, 'erased' => 0, 'skipped' => 0], $job->run(Carbon::now()));
    }
}

final class FakePrivacyRepository implements PrivacyRepository
{
    /** @var list<array{string, int, string, string, ?string, ?int, ?array<string, int>}> */
    public array $journal = [];

    public ?int $months = null;

    public function journal(DataSubject $subject, string $action, string $trigger, ?string $reason, ?int $actorId, ?array $counts): void
    {
        $this->journal[] = [$subject->type->value, $subject->id, $action, $trigger, $reason, $actorId, $counts];
    }

    /** @return Collection<int, PrivacyRequest> */
    public function requestsFor(DataSubject $subject, int $limit): Collection
    {
        return new Collection;
    }

    public function retentionRejectedMonths(): ?int
    {
        return $this->months;
    }

    public function setRetentionRejectedMonths(?int $months): ?int
    {
        return $this->months = $months;
    }
}
