<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Privacy;

use App\Modules\Core\Contracts\PersonalDataProvider;
use App\Modules\Core\Contracts\RetentionSource;
use App\Modules\Core\DTO\DataSubject;
use App\Modules\Core\Enums\DataSubjectType;
use App\Modules\Recruiting\Enums\ApplicationStatus;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\CandidateScreening;
use App\Modules\Recruiting\Models\StageChange;
use App\Modules\Recruiting\Models\Touchpoint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recruiting's share of a candidate's data: profile, profile links, applications with stage history, every
 * touchpoint (messages, calls, notes, meetings) and AI screenings.
 *
 * Erase keeps what reports count — the candidate row (city, channel, UTM, dates), applications (vacancy, stage,
 * status, reject reason, dates), stage changes and touchpoint rows (channel, direction, author, date) — and wipes
 * the rest: name → "Видалений кандидат #id", contacts, tags, profile links, reject notes, stage-change comments,
 * message bodies and meta (recording links, meeting links, attendees), external ids; AI screenings are deleted.
 */
final readonly class CandidatePersonalData implements PersonalDataProvider, RetentionSource
{
    public function section(): string
    {
        return 'recruiting';
    }

    public function blocker(DataSubject $subject, bool $erase): ?string
    {
        if ($subject->type !== DataSubjectType::Candidate) {
            return null;
        }

        return Candidate::query()->whereKey($subject->id)->exists() ? null : 'not_found';
    }

    public function export(DataSubject $subject): array
    {
        $candidate = $this->candidate($subject);
        if ($candidate === null) {
            return [];
        }
        $candidate->load(['city', 'channel']);
        $applications = Application::query()->with(['vacancy', 'stage', 'rejectReason'])
            ->where('candidate_id', $candidate->id)->orderBy('id')->get();
        $touchpoints = Touchpoint::query()->where('candidate_id', $candidate->id)->orderBy('occurred_at')->orderBy('id')->get();
        $of = fn (bool $wanted, Channel ...$channels): array => $touchpoints
            ->filter(static fn (Touchpoint $t): bool => in_array($t->channel, $channels, true) === $wanted)
            ->map(fn (Touchpoint $t): array => $this->touchpoint($t))->values()->all();

        return [
            'profile' => [
                'id' => $candidate->id,
                'full_name' => $candidate->full_name,
                'phone' => $candidate->phone,
                'email' => $candidate->email,
                'telegram_username' => $candidate->telegram_username,
                'city' => $candidate->city?->name,
                'source' => $candidate->source->value,
                'channel' => $candidate->channel?->name,
                'added_via' => $candidate->added_via?->value,
                'utm' => $candidate->utm,
                'tags' => $candidate->tags ?? [],
                'created_at' => $candidate->created_at?->toIso8601String(),
                'anonymized_at' => $candidate->anonymized_at?->toIso8601String(),
            ],
            'profile_urls' => DB::table('candidate_profile_urls')->where('candidate_id', $candidate->id)->orderBy('id')
                ->get(['site', 'url', 'created_at'])->map(static fn (object $r): array => (array) $r)->all(),
            'applications' => $applications->map(static fn (Application $a): array => [
                'id' => $a->id,
                'vacancy' => $a->vacancy->title,
                'stage' => $a->stage->name,
                'status' => $a->status->value,
                'reject_reason' => $a->rejectReason?->name,
                'rejected_note' => $a->rejected_note,
                'created_at' => $a->created_at?->toIso8601String(),
                'closed_at' => $a->closed_at?->toIso8601String(),
                'stage_history' => StageChange::query()->with('toStage')->where('application_id', $a->id)->orderBy('at')->get()
                    ->map(static fn (StageChange $c): array => ['stage' => $c->toStage->name, 'reason' => $c->reason, 'at' => $c->at->toIso8601String()])
                    ->all(),
            ])->all(),
            'touchpoints' => $of(false, Channel::Note, Channel::Meeting),
            'notes' => $of(true, Channel::Note),
            'meetings' => $of(true, Channel::Meeting),
            'screenings' => CandidateScreening::query()->with('vacancy')->where('candidate_id', $candidate->id)->orderBy('id')->get()
                ->map(static fn (CandidateScreening $s): array => [
                    'vacancy' => $s->vacancy->title,
                    'status' => $s->status,
                    'score' => $s->score,
                    'verdict' => $s->verdict,
                    'summary' => $s->summary,
                    'strengths' => $s->strengths ?? [],
                    'gaps' => $s->gaps ?? [],
                    'questions' => $s->questions ?? [],
                    'created_at' => $s->created_at?->toIso8601String(),
                ])->all(),
        ];
    }

    public function erase(DataSubject $subject): array
    {
        $candidate = $this->candidate($subject);
        if ($candidate === null) {
            return [];
        }
        $applicationIds = Application::query()->where('candidate_id', $candidate->id)->pluck('id')->all();

        $candidate->forceFill([
            'full_name' => 'Видалений кандидат #'.$candidate->id,
            'phone' => null,
            'email' => null,
            'telegram_username' => null,
            'tags' => null,
            'anonymized_at' => $candidate->anonymized_at ?? Carbon::now(),
        ])->save();

        return [
            'profile_urls' => DB::table('candidate_profile_urls')->where('candidate_id', $candidate->id)->delete(),
            'applications' => Application::query()->whereKey($applicationIds)->whereNotNull('rejected_note')->update(['rejected_note' => null]),
            'stage_changes' => StageChange::query()->whereIn('application_id', $applicationIds)->whereNotNull('reason')->update(['reason' => null]),
            'touchpoints' => Touchpoint::query()->where('candidate_id', $candidate->id)
                ->update(['body' => null, 'meta' => null, 'external_id' => null]),
            'screenings' => CandidateScreening::query()->where('candidate_id', $candidate->id)->delete(),
        ];
    }

    /** Candidates with at least one application, all of them rejected and closed before $before. */
    public function expired(Carbon $before, int $limit): array
    {
        return Candidate::query()
            ->whereNull('anonymized_at')
            ->whereHas('applications')
            ->whereDoesntHave('applications', static fn (Builder $q): Builder => $q->where(static fn (Builder $w): Builder => $w
                ->where('status', '!=', ApplicationStatus::Rejected->value)
                ->orWhereNull('closed_at')
                ->orWhere('closed_at', '>=', $before)))
            ->orderBy('id')->limit($limit)->pluck('id')
            ->map(static fn (int $id): DataSubject => DataSubject::candidate($id))->values()->all();
    }

    /** @return array<string, mixed> */
    private function touchpoint(Touchpoint $t): array
    {
        return [
            'channel' => $t->channel->value,
            'direction' => $t->direction->value,
            'occurred_at' => $t->occurred_at->toIso8601String(),
            'body' => $t->body,
            'meta' => $t->meta,
        ];
    }

    private function candidate(DataSubject $subject): ?Candidate
    {
        return $subject->type === DataSubjectType::Candidate ? Candidate::query()->find($subject->id) : null;
    }
}
