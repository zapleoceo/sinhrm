<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\Contracts\TouchpointEvaluations;
use App\Modules\Recruiting\Contracts\TouchpointRepository;
use App\Modules\Recruiting\DTO\TimelineEntry;
use App\Modules\Recruiting\DTO\TouchpointData;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Events\TouchpointRecorded;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Touchpoint;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

/** Touches logged in the product and the merged candidate timeline. */
final readonly class TouchpointService
{
    public function __construct(
        private TouchpointRepository $touchpoints,
        private ApplicationRepository $applications,
        private Dispatcher $events,
        private TouchpointEvaluations $evaluations,
    ) {}

    /**
     * Logs a manual touch (via_product = true). application_id, when given, must belong to the candidate;
     * otherwise the candidate's latest active application is used.
     *
     * @throws RecruitingException
     */
    public function log(User $actor, Candidate $candidate, TouchpointData $data): Touchpoint
    {
        $applicationId = $data->applicationId;
        if ($applicationId !== null) {
            $application = $this->applications->find($applicationId);
            if ($application === null || $application->candidate_id !== $candidate->id) {
                throw RecruitingException::applicationMismatch();
            }
        } else {
            $applicationId = $this->applications->latestActiveFor($candidate->id)?->id;
        }

        $touchpoint = $this->touchpoints->create([
            'candidate_id' => $candidate->id,
            'application_id' => $applicationId,
            'channel' => $data->channel->value,
            'direction' => $data->direction->value,
            'author_id' => $actor->id,
            'occurred_at' => $data->occurredAt,
            'body' => $data->body,
            'meta' => $data->meta === [] ? null : $data->meta,
            'via_product' => true,
        ]);
        $this->events->dispatch(new TouchpointRecorded($touchpoint));

        return $touchpoint->load('author');
    }

    /**
     * @param  list<Channel>|null  $channels
     * @return LengthAwarePaginator<int, TimelineEntry>
     */
    public function timeline(Candidate $candidate, ?array $channels, bool $withStages, int $perPage): LengthAwarePaginator
    {
        $page = $this->touchpoints->timeline($candidate->id, $channels, $withStages, $perPage);
        $ids = [];
        foreach ($page->items() as $entry) {
            if ($entry->item instanceof Touchpoint) {
                $ids[] = $entry->item->id;
            }
        }
        $summaries = $ids === [] ? [] : $this->evaluations->summaries($ids);
        if ($summaries === []) {
            return $page;
        }
        $entries = array_map(
            static fn (TimelineEntry $e): TimelineEntry => $e->item instanceof Touchpoint && isset($summaries[$e->item->id])
                ? $e->withEvaluation($summaries[$e->item->id])
                : $e,
            $page->items(),
        );

        return new Paginator($entries, $page->total(), $page->perPage(), $page->currentPage(), [
            'path' => Paginator::resolveCurrentPath(),
        ]);
    }
}
