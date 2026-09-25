<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\Contracts\TouchpointRepository;
use App\Modules\Recruiting\DTO\CandidateData;
use App\Modules\Recruiting\Enums\CandidateSource;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Events\TouchpointRecorded;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Recruiting\Support\ContactNormalizer;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Messages captured from outside that matched no candidate: list, link to a candidate, or create one from them. */
final readonly class InboxService
{
    public function __construct(
        private TouchpointRepository $touchpoints,
        private ApplicationRepository $applications,
        private CandidateService $candidates,
        private RecruitingScope $scope,
        private ContactNormalizer $normalizer,
        private Dispatcher $events,
    ) {}

    /** @return LengthAwarePaginator<int, Touchpoint> */
    public function list(User $actor, int $perPage): LengthAwarePaginator
    {
        return $this->touchpoints->inbox($this->scope->for($actor), $perPage);
    }

    /** @throws ModelNotFoundException<Touchpoint> */
    public function find(int $id): Touchpoint
    {
        return $this->touchpoints->find($id) ?? throw (new ModelNotFoundException)->setModel(Touchpoint::class, [$id]);
    }

    /** @throws RecruitingException already_linked */
    public function link(Touchpoint $touchpoint, Candidate $candidate): Touchpoint
    {
        if ($touchpoint->candidate_id !== null) {
            throw RecruitingException::alreadyLinked();
        }
        $this->touchpoints->update($touchpoint, [
            'candidate_id' => $candidate->id,
            'application_id' => $this->applications->latestActiveFor($candidate->id)?->id,
        ]);
        $this->events->dispatch(new TouchpointRecorded($touchpoint));

        return $touchpoint;
    }

    /**
     * New candidate from an unmatched message: the sender's contact becomes the candidate's phone / e-mail /
     * Telegram (dedupe applies), source = "inbox", optionally applied to a vacancy.
     *
     * @throws RecruitingException
     */
    public function createCandidate(User $actor, Touchpoint $touchpoint, string $fullName, ?int $vacancyId): Candidate
    {
        if ($touchpoint->candidate_id !== null) {
            throw RecruitingException::alreadyLinked();
        }
        $contact = $touchpoint->contact();
        $keys = $this->normalizer->guess($contact);
        if ($touchpoint->channel === Channel::Telegram && $keys->telegram === null && $keys->phone === null) {
            $keys = $this->normalizer->keys(null, null, $contact);
        }
        $candidate = $this->candidates->create($actor, new CandidateData(
            fullName: $fullName,
            phone: $keys->phone,
            email: $keys->email,
            telegram: $keys->telegram,
            source: CandidateSource::Inbox,
            vacancyId: $vacancyId,
        ));
        $this->link($touchpoint, $candidate);

        return $candidate;
    }
}
