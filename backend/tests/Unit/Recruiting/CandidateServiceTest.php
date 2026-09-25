<?php

declare(strict_types=1);

namespace Tests\Unit\Recruiting;

use App\Models\User;
use App\Modules\Directory\Contracts\AccessibleBranches;
use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\Contracts\CandidateRepository;
use App\Modules\Recruiting\Contracts\PipelineRepository;
use App\Modules\Recruiting\Contracts\TouchpointRepository;
use App\Modules\Recruiting\Contracts\VacancyRepository;
use App\Modules\Recruiting\DTO\CandidateData;
use App\Modules\Recruiting\DTO\ContactKeys;
use App\Modules\Recruiting\Enums\CandidateSource;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Services\ApplicationService;
use App\Modules\Recruiting\Services\CandidateService;
use App\Modules\Recruiting\Services\RecruitingScope;
use App\Modules\Recruiting\Support\ContactNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CandidateServiceTest extends TestCase
{
    private CandidateRepository&MockObject $candidates;

    private ApplicationRepository&MockObject $applications;

    private CandidateService $service;

    protected function setUp(): void
    {
        $this->candidates = $this->createMock(CandidateRepository::class);
        $this->applications = $this->createMock(ApplicationRepository::class);
        $this->applications->method('transaction')->willReturnCallback(fn (callable $cb): mixed => $cb());
        $touchpoints = $this->createMock(TouchpointRepository::class);
        $this->service = new CandidateService(
            $this->candidates,
            $this->applications,
            $this->createMock(VacancyRepository::class),
            new ApplicationService($this->applications, $this->createMock(PipelineRepository::class), $touchpoints, new NullLogger),
            new RecruitingScope($this->createMock(AccessibleBranches::class), $this->candidates, $touchpoints),
            new ContactNormalizer,
            new NullLogger,
        );
    }

    public function test_duplicate_contact_throws_409_with_existing_id(): void
    {
        $existing = (new Candidate)->forceFill(['id' => 42]);
        $this->candidates->method('isVisible')->willReturn(true);
        $this->candidates->expects($this->once())->method('findByContacts')
            ->with(new ContactKeys('+380671234567', 'a@example.test', null))
            ->willReturn([$existing, 'phone']);
        $this->candidates->expects($this->never())->method('create');

        try {
            $this->service->create($this->actor(), new CandidateData(fullName: 'X Y', phone: '0671234567', email: 'A@example.test'));
            $this->fail('expected a duplicate');
        } catch (RecruitingException $e) {
            $this->assertSame('duplicate_candidate', $e->errorCode);
            $this->assertSame(409, $e->status);
            $this->assertSame(['existing_id' => 42, 'matched_by' => 'phone'], $e->extra);
        }
    }

    public function test_out_of_scope_duplicate_hides_id_and_field(): void
    {
        $this->candidates->method('findByContacts')->willReturn([(new Candidate)->forceFill(['id' => 42]), 'email']);
        $this->candidates->method('isVisible')->willReturn(false);

        try {
            $this->service->create($this->actor(), new CandidateData(fullName: 'X Y', email: 'a@example.test'));
            $this->fail('expected a duplicate');
        } catch (RecruitingException $e) {
            $this->assertSame('duplicate_candidate', $e->errorCode);
            $this->assertSame(['restricted' => true], $e->extra);
        }
    }

    public function test_creates_with_normalized_contacts_when_no_match(): void
    {
        $this->candidates->method('findByContacts')->willReturn(null);
        $this->candidates->expects($this->once())->method('create')
            ->with($this->callback(fn (array $a): bool => $a['phone'] === '+380671234567'
                && $a['telegram_username'] === 'handle_x'
                && $a['source'] === 'manual'
                && $a['owner_id'] === 5
                && $a['created_by'] === 5))
            ->willReturn((new Candidate)->forceFill(['id' => 1]));

        $this->service->create($this->actor(), new CandidateData(fullName: 'X Y', phone: '067 123 45 67', telegram: '@Handle_X'));
    }

    public function test_concurrent_insert_is_mapped_to_the_same_409(): void
    {
        $existing = (new Candidate)->forceFill(['id' => 42]);
        $this->candidates->method('findByContacts')->willReturnOnConsecutiveCalls(null, [$existing, 'phone']);
        $this->candidates->method('isVisible')->willReturn(true);
        $this->candidates->method('create')->willThrowException(
            new UniqueConstraintViolationException('pgsql', 'insert into candidates', [], new \PDOException('23505')),
        );

        try {
            $this->service->create($this->actor(), new CandidateData(fullName: 'X Y', phone: '0671234567'));
            $this->fail('expected a duplicate');
        } catch (RecruitingException $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame(['existing_id' => 42, 'matched_by' => 'phone'], $e->extra);
        }
    }

    public function test_from_array_is_import_ready(): void
    {
        $data = CandidateData::fromArray([
            'full_name' => '  Some One ',
            'phone' => '0671234567',
            'telegram' => '@one',
            'source' => 'unknown-board',
            'utm' => ['utm_source' => 'x', 'bad' => ['nested']],
            'tags' => ['a', '', 3],
            'vacancy_id' => '7',
        ]);

        $this->assertSame('Some One', $data->fullName);
        $this->assertSame('@one', $data->telegram);
        $this->assertSame(CandidateSource::Import, $data->source);
        $this->assertSame(['utm_source' => 'x'], $data->utm);
        $this->assertSame(['a', '3'], $data->tags);
        $this->assertSame(7, $data->vacancyId);
    }

    private function actor(): User
    {
        return (new User)->forceFill(['id' => 5]);
    }
}
