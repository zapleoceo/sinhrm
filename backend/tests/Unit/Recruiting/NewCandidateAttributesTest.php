<?php

declare(strict_types=1);

namespace Tests\Unit\Recruiting;

use App\Models\User;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Directory\Contracts\AccessibleBranches;
use App\Modules\Recruiting\Contracts\AcquisitionChannelRepository;
use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\Contracts\CandidateRepository;
use App\Modules\Recruiting\Contracts\HiringTeamRepository;
use App\Modules\Recruiting\Contracts\PipelineRepository;
use App\Modules\Recruiting\Contracts\TouchpointRepository;
use App\Modules\Recruiting\Contracts\VacancyRepository;
use App\Modules\Recruiting\DTO\CandidateData;
use App\Modules\Recruiting\Enums\AddedVia;
use App\Modules\Recruiting\Enums\CandidateSource;
use App\Modules\Recruiting\Http\Requests\AssignInterviewersRequest;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Services\AcquisitionChannelService;
use App\Modules\Recruiting\Services\ApplicationService;
use App\Modules\Recruiting\Services\CandidateService;
use App\Modules\Recruiting\Services\RecruitingScope;
use App\Modules\Recruiting\Support\ContactNormalizer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Columns of a new candidate are built in one place for manual create and for import/match. */
final class NewCandidateAttributesTest extends TestCase
{
    private CandidateRepository&MockObject $candidates;

    private CandidateService $service;

    protected function setUp(): void
    {
        $this->candidates = $this->createMock(CandidateRepository::class);
        $applications = $this->createMock(ApplicationRepository::class);
        $applications->method('transaction')->willReturnCallback(fn (callable $cb): mixed => $cb());
        $touchpoints = $this->createMock(TouchpointRepository::class);
        $branches = $this->createMock(AccessibleBranches::class);
        $branches->method('for')->willReturn([]);
        $this->service = new CandidateService(
            $this->candidates,
            $applications,
            $this->createMock(VacancyRepository::class),
            new ApplicationService($applications, $this->createMock(PipelineRepository::class), $touchpoints, new NullLogger),
            new RecruitingScope($branches, $this->candidates, $touchpoints, $this->createMock(HiringTeamRepository::class)),
            new ContactNormalizer,
            new NullLogger,
            new AcquisitionChannelService($this->createMock(AcquisitionChannelRepository::class)),
        );
    }

    public function test_manual_create_defaults_to_manual_and_the_actor(): void
    {
        $this->candidates->method('findByContacts')->willReturn(null);
        $this->candidates->expects($this->once())->method('create')->with([
            'full_name' => 'X Y',
            'phone' => '+380671234567',
            'email' => null,
            'telegram_username' => null,
            'city_id' => 3,
            'source' => 'manual',
            'channel_id' => null,
            'added_via' => 'manual',
            'utm' => null,
            'tags' => ['a'],
            'owner_id' => 5,
            'created_by' => 5,
        ])->willReturn((new Candidate)->forceFill(['id' => 1]));

        $this->service->create((new User)->forceFill(['id' => 5]), new CandidateData(fullName: 'X Y', phone: '0671234567', cityId: 3, tags: ['a']));
    }

    public function test_import_match_without_actor_defaults_to_import_and_keeps_explicit_owner(): void
    {
        $this->candidates->method('findByContacts')->willReturn(null);
        $this->candidates->expects($this->once())->method('create')->with([
            'full_name' => 'Some One',
            'phone' => null,
            'email' => 'one@example.test',
            'telegram_username' => null,
            'city_id' => null,
            'source' => 'import',
            'channel_id' => null,
            'added_via' => 'sheets',
            'utm' => ['utm_source' => 'x'],
            'tags' => null,
            'owner_id' => 9,
            'created_by' => null,
        ])->willReturn((new Candidate)->forceFill(['id' => 2]));

        $match = $this->service->createOrMatch(null, new CandidateData(
            fullName: 'Some One', email: 'one@example.test', source: CandidateSource::Import, utm: ['utm_source' => 'x'], ownerId: 9, addedVia: AddedVia::Sheets,
        ));

        $this->assertTrue($match->created);
    }

    public function test_interviewers_must_be_active_users(): void
    {
        $rule = (string) (new AssignInterviewersRequest)->rules()['user_ids.*'][2];

        $this->assertStringContainsString('status', $rule);
        $this->assertStringContainsString('"'.UserStatus::Active->value.'"', $rule);
    }
}
