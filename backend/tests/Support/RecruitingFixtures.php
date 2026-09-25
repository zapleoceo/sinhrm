<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\Contracts\TouchpointIngestor;
use App\Modules\Recruiting\DTO\IncomingMessage;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\Direction;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Pipeline;
use App\Modules\Recruiting\Models\PipelineStage;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Services\ApplicationService;
use Illuminate\Support\Carbon;

/**
 * Builders for Recruiting tests. Synthetic data only. Needs RefreshDatabase (the default pipeline comes from the
 * data migration).
 */
trait RecruitingFixtures
{
    /** @param  list<Branch>  $branches */
    protected function userWith(UserRole $role, array $branches = []): User
    {
        $user = User::factory()->withRole($role)->create();
        if ($branches !== []) {
            $user->branches()->sync(array_map(static fn (Branch $b): int => $b->id, $branches));
        }

        return $user;
    }

    protected function vacancyIn(Branch $branch, ?User $recruiter = null): Vacancy
    {
        return Vacancy::factory()->create([
            'branch_id' => $branch->id,
            'recruiter_id' => ($recruiter ?? User::factory()->create())->id,
        ]);
    }

    /**
     * Candidate applied to the vacancy through the real service (first stage + stage change + system touchpoint).
     *
     * @param  array<string, mixed>  $candidate
     */
    protected function applied(Vacancy $vacancy, array $candidate = [], ?Carbon $at = null): Application
    {
        $model = Candidate::factory()->create($candidate);

        return $this->app->make(ApplicationService::class)->apply(null, $model, $vacancy, $at);
    }

    protected function defaultPipeline(): Pipeline
    {
        $pipeline = Pipeline::query()->with('stages')->where('is_default', true)->first();
        assert($pipeline instanceof Pipeline);

        return $pipeline;
    }

    protected function stageAt(int $position): PipelineStage
    {
        $stage = $this->defaultPipeline()->stages->firstWhere('position', $position);
        assert($stage instanceof PipelineStage);

        return $stage;
    }

    protected function rejectStage(): PipelineStage
    {
        $stage = $this->defaultPipeline()->stages->first(fn (PipelineStage $s): bool => $s->isReject());
        assert($stage instanceof PipelineStage);

        return $stage;
    }

    protected function hireStage(): PipelineStage
    {
        $stage = $this->defaultPipeline()->stages->first(fn (PipelineStage $s): bool => $s->isHire());
        assert($stage instanceof PipelineStage);

        return $stage;
    }

    /** @param  array<string, mixed>  $extra */
    protected function ingest(Channel $channel, ?string $contact, array $extra = []): Touchpoint
    {
        return $this->app->make(TouchpointIngestor::class)->ingest(new IncomingMessage(
            channel: $channel,
            direction: $extra['direction'] ?? Direction::In,
            occurredAt: $extra['at'] ?? Carbon::now(),
            contact: $contact,
            body: $extra['body'] ?? 'Hello',
            externalId: $extra['external_id'] ?? null,
            integrationKey: $extra['integration_key'] ?? 'test',
            branchId: $extra['branch_id'] ?? null,
            authorId: $extra['author_id'] ?? null,
            viaProduct: $extra['via_product'] ?? false,
        ));
    }
}
