<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\Contracts\CandidateRepository;
use App\Modules\Recruiting\Contracts\VacancyRepository;
use App\Modules\Recruiting\DTO\ClipData;
use App\Modules\Recruiting\DTO\ClipResult;
use App\Modules\Recruiting\DTO\ContactKeys;
use App\Modules\Recruiting\DTO\TouchpointData;
use App\Modules\Recruiting\DTO\VacancyFilter;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\Direction;
use App\Modules\Recruiting\Enums\VacancyStatus;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Support\ContactNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Browser extension import of one candidate profile page. Dedupe: the normalized profile URL first, then phone /
 * e-mail / Telegram (global). A match the actor may not see → 409 duplicate_candidate {restricted}; a visible match is
 * reused (URL linked, applied to the vacancy if not yet). A note "Imported from <site>" is added for a new candidate
 * and whenever a match got a new URL or application — repeated clicks on the same page add nothing.
 */
final readonly class ClipperService
{
    public const int SUMMARY_LIMIT = 2000;

    public function __construct(
        private CandidateRepository $candidates,
        private ApplicationRepository $applications,
        private VacancyRepository $vacancies,
        private ApplicationService $applicationService,
        private TouchpointService $touchpoints,
        private RecruitingScope $scope,
        private ContactNormalizer $normalizer,
        private LoggerInterface $log,
    ) {}

    /** @return list<Vacancy> open vacancies of the user's scope (the popup's optional "apply to" list) */
    public function vacancies(User $actor): array
    {
        $page = $this->vacancies->paginate($this->scope->for($actor), new VacancyFilter(status: VacancyStatus::Open, perPage: 200));

        return array_values($page->items());
    }

    /** @throws RecruitingException */
    public function import(User $actor, ClipData $data): ClipResult
    {
        $vacancy = $this->vacancy($actor, $data->vacancyId);
        $keys = $this->normalizer->keys($data->phone, $data->email, $data->telegram);
        $existing = $this->match($data->profileUrl, $keys);
        if ($existing !== null) {
            return $this->reuse($actor, $existing, $data, $vacancy);
        }

        try {
            $candidate = $this->applications->transaction(function () use ($actor, $data, $keys, $vacancy): Candidate {
                $candidate = $this->candidates->create([
                    'full_name' => $data->fullName,
                    'phone' => $keys->phone,
                    'email' => $keys->email,
                    'telegram_username' => $keys->telegram,
                    'source' => $data->site->candidateSource()->value,
                    'owner_id' => $actor->id,
                    'created_by' => $actor->id,
                ]);
                $this->candidates->attachProfileUrl($candidate->id, $data->site->value, $data->profileUrl);
                if ($vacancy !== null) {
                    $this->applicationService->apply($actor, $candidate, $vacancy);
                }
                $this->note($actor, $candidate, $data);

                return $candidate;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent import of the same person won the race: treat it as a match.
            $existing = $this->match($data->profileUrl, $keys) ?? throw RecruitingException::duplicateCandidateRestricted();

            return $this->reuse($actor, $existing, $data, $vacancy);
        }
        $this->log->info('recruiting.candidate_created', ['id' => $candidate->id, 'by' => $actor->id, 'via' => 'extension']);

        return new ClipResult($candidate, true);
    }

    private function match(string $profileUrl, ContactKeys $keys): ?Candidate
    {
        return $this->candidates->findByProfileUrl($profileUrl)
            ?? ($keys->isEmpty() ? null : ($this->candidates->findByContacts($keys)[0] ?? null));
    }

    /** @throws RecruitingException */
    private function reuse(User $actor, Candidate $candidate, ClipData $data, ?Vacancy $vacancy): ClipResult
    {
        if (! $this->scope->canSeeCandidate($actor, $candidate)) {
            throw RecruitingException::duplicateCandidateRestricted();
        }
        $linked = $this->candidates->attachProfileUrl($candidate->id, $data->site->value, $data->profileUrl);
        $applied = false;
        if ($vacancy !== null && $this->applications->findFor($candidate->id, $vacancy->id) === null) {
            $this->applicationService->apply($actor, $candidate, $vacancy);
            $applied = true;
        }
        if ($linked || $applied) {
            $this->note($actor, $candidate, $data);
        }
        $this->log->info('recruiting.candidate_matched', ['id' => $candidate->id, 'by' => $actor->id, 'via' => 'extension']);

        return new ClipResult($candidate, false);
    }

    /** @throws RecruitingException */
    private function vacancy(User $actor, ?int $id): ?Vacancy
    {
        if ($id === null) {
            return null;
        }
        $vacancy = $this->vacancies->find($id);
        if ($vacancy === null || ! $this->scope->canSeeVacancy($actor, $vacancy)) {
            throw RecruitingException::vacancyOutOfScope();
        }

        return $vacancy;
    }

    private function note(User $actor, Candidate $candidate, ClipData $data): void
    {
        $summary = $data->summary === null ? null : mb_substr($data->summary, 0, self::SUMMARY_LIMIT);
        $lines = array_filter(
            ['Imported from '.$data->site->label().': '.$data->profileUrl, $data->headline, $data->location, $summary],
            static fn (?string $line): bool => $line !== null && $line !== '',
        );

        $this->touchpoints->log($actor, $candidate, new TouchpointData(
            channel: Channel::Note,
            direction: Direction::In,
            body: implode("\n\n", $lines),
            occurredAt: Carbon::now(),
            meta: ['source' => 'extension', 'site' => $data->site->value, 'profile_url' => $data->profileUrl],
        ));
    }
}
