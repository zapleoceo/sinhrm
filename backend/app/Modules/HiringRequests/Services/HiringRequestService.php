<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Services;

use App\Models\User;
use App\Modules\Core\Contracts\WorkingCalendar;
use App\Modules\HiringRequests\Contracts\HiringRequestRepository;
use App\Modules\HiringRequests\Enums\ApprovalStatus;
use App\Modules\HiringRequests\Enums\HiringReason;
use App\Modules\HiringRequests\Enums\HiringRequestStatus;
use App\Modules\HiringRequests\Exceptions\HiringException;
use App\Modules\HiringRequests\Models\HiringApproval;
use App\Modules\HiringRequests\Models\HiringRequest;
use App\Modules\HiringRequests\Support\FormFields;
use App\Modules\HiringRequests\Support\RequestAttributes;
use App\Modules\HiringRequests\Support\VacancyDraft;
use App\Modules\Recruiting\DTO\VacancyData;
use App\Modules\Recruiting\Enums\VacancyStatus;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Services\VacancyService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Hiring requests (tz2): draft → submit (the route template is copied into the request) → each step approves or
 * rejects in order (SLA per step) → final approval opens a prefilled vacancy (settings.auto_vacancy) and links it
 * (in_progress) → closed when the vacancy is closed or the headcount is hired (job hiring.sla) or by HR.
 * All status changes are compare-and-set, so concurrent clicks never double-approve or double-create a vacancy.
 */
final readonly class HiringRequestService
{
    public const int LIMIT = 300;

    public function __construct(
        private HiringRequestRepository $requests,
        private HiringAccess $access,
        private ApproverNotifier $notifier,
        private RouteSnapshot $route,
        private HiringProgress $progress,
        private VacancyService $vacancies,
        private LoggerInterface $log,
        private WorkingCalendar $calendar,
    ) {}

    /**
     * @param  array{status?: string|null, mine?: bool}  $filter
     * @return Collection<int, HiringRequest>
     */
    public function list(User $user, array $filter): Collection
    {
        $admin = $this->access->isAdmin($user);

        return $this->requests->list($admin && ! ($filter['mine'] ?? false) ? null : $user->id, $this->access->roles($user), $filter, self::LIMIT);
    }

    /** @return Collection<int, HiringRequest> requests whose current step the user may decide now */
    public function inbox(User $user): Collection
    {
        return $this->requests->pending(self::LIMIT)
            ->filter(fn (HiringRequest $r): bool => $this->access->canDecide($user, $r, $r->currentApproval()))
            ->values();
    }

    public function findVisible(User $user, int $id): HiringRequest
    {
        $request = $this->requests->find($id);
        if ($request === null || ! $this->access->canSee($user, $request)) {
            abort(404);
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws HiringException
     */
    public function create(User $user, array $data, bool $submit, ?Carbon $now = null): HiringRequest
    {
        abort_unless($this->access->canCreate($user), 403);
        $attributes = $this->attributes($data, true);
        $request = $this->requests->create($attributes + ['requester_id' => $user->id, 'status' => HiringRequestStatus::Draft->value]);
        $this->log->info('hiring.request_created', ['id' => $request->id, 'by' => $user->id]);

        return $submit ? $this->submit($user, $request, $now) : $this->reload($request);
    }

    /**
     * Draft only, by the requester or HR.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws HiringException
     */
    public function update(User $user, HiringRequest $request, array $data): HiringRequest
    {
        // canEdit() is already false outside Draft, so a non-Draft request falls through to assertStatus() → 409
        // ("invalid_status"); in Draft only the requester/HR pass. No write happens on either path.
        abort_unless($this->access->canEdit($user, $request) || $request->status !== HiringRequestStatus::Draft, 403);
        $this->assertStatus($request, [HiringRequestStatus::Draft]);
        $this->requests->update($request, $this->attributes($data + $request->only(['reason', 'replaced_employee_id', 'salary_min', 'salary_max']), false, $data));

        return $this->reload($request);
    }

    /** @throws HiringException */
    public function submit(User $user, HiringRequest $request, ?Carbon $now = null): HiringRequest
    {
        // canEdit() is already false outside Draft, so a non-Draft request falls through to assertStatus() → 409
        // ("invalid_status"); in Draft only the requester/HR pass. No write happens on either path.
        abort_unless($this->access->canEdit($user, $request) || $request->status !== HiringRequestStatus::Draft, 403);
        $this->assertStatus($request, [HiringRequestStatus::Draft]);
        $now ??= Carbon::now();
        $missing = FormFields::missing($this->fields(), $request->extra ?? []);
        if ($missing !== []) {
            throw HiringException::requiredFields($missing);
        }
        if ($request->reason === HiringReason::Replacement && $request->replaced_employee_id === null) {
            throw HiringException::replacedEmployeeRequired();
        }

        $this->requests->transaction(function () use ($request, $now): void {
            if (! $this->requests->transition($request, HiringRequestStatus::Draft, ['status' => HiringRequestStatus::Pending->value, 'submitted_at' => $now])) {
                throw HiringException::invalidStatus($request->status->value);
            }
            $this->requests->createApprovals($request, $this->route->of($request));
        });
        $this->log->info('hiring.request_submitted', ['id' => $request->id, 'by' => $user->id]);

        return $this->advance($this->reload($request), $now, null);
    }

    /**
     * Approve or reject the current step. On the last approval: approved → vacancy (auto) → in_progress.
     *
     * @throws HiringException
     */
    public function decide(User $user, HiringRequest $request, bool $approve, ?string $comment, ?int $recruiterId = null, ?Carbon $now = null): HiringRequest
    {
        $now ??= Carbon::now();
        $step = $request->currentApproval();
        if ($request->status !== HiringRequestStatus::Pending || $step === null) {
            throw HiringException::invalidStatus($request->status->value);
        }
        abort_unless($this->access->canDecide($user, $request, $step), 403);
        // One transaction: a rejection is the decision of the step, the request status and the skipped rest of the route
        // together — a failure in between must not leave a Pending request with a rejected step. Mails/tasks go after commit.
        $decided = $this->requests->transaction(function () use ($user, $request, $step, $approve, $comment, $now): bool {
            if (! $this->requests->transitionApproval($step, ApprovalStatus::Pending, [
                'status' => ($approve ? ApprovalStatus::Approved : ApprovalStatus::Rejected)->value,
                'decided_by' => $user->id,
                'decided_at' => $now,
                'comment' => $comment,
            ])) {
                return false;
            }
            if (! $approve) {
                $this->requests->transition($request, HiringRequestStatus::Pending, ['status' => HiringRequestStatus::Rejected->value, 'decided_at' => $now]);
                $this->requests->skipOpenApprovals($request);
            }

            return true;
        });
        if (! $decided) {
            throw HiringException::invalidStatus($request->status->value);
        }
        $this->notifier->closeStep($step, $now);
        $this->log->info('hiring.step_decided', ['id' => $request->id, 'step' => $step->position, 'approve' => $approve, 'by' => $user->id]);
        if (! $approve) {
            $this->notifier->decided($request, false);

            return $this->reload($request);
        }

        return $this->advance($this->reload($request), $now, $recruiterId ?? $user->id);
    }

    public function cancel(User $user, HiringRequest $request, ?Carbon $now = null): HiringRequest
    {
        abort_unless($this->access->canCancel($user, $request), $request->status->isCancellable() ? 403 : 409);
        $now ??= Carbon::now();
        // The status change and the skipped route are one transaction; task closing follows the commit.
        $cancelled = $this->requests->transaction(function () use ($request, $now): bool {
            if (! $this->requests->transition($request, $request->status, ['status' => HiringRequestStatus::Cancelled->value, 'closed_at' => $now])) {
                return false;
            }
            $this->requests->skipOpenApprovals($request);

            return true;
        });
        if (! $cancelled) {
            throw HiringException::invalidStatus($request->status->value);
        }
        foreach ($request->approvals as $a) {
            $this->notifier->closeStep($a, $now);
        }

        return $this->reload($request);
    }

    /** HR closes an approved / in-progress request (hired enough, position frozen, …). */
    public function close(User $user, HiringRequest $request, ?Carbon $now = null): HiringRequest
    {
        abort_unless($this->access->isAdmin($user), 403);
        $this->assertStatus($request, [HiringRequestStatus::Approved, HiringRequestStatus::InProgress]);
        if (! $this->requests->transition($request, $request->status, ['status' => HiringRequestStatus::Closed->value, 'closed_at' => $now ?? Carbon::now()])) {
            throw HiringException::invalidStatus($request->status->value);
        }

        return $this->reload($request);
    }

    /**
     * Opens and links the vacancy of an approved request (HR, or automatically on final approval). Idempotent: a
     * request that already has a vacancy returns it unchanged — the request row is locked while creating.
     *
     * @throws HiringException
     */
    public function createVacancy(HiringRequest $request, ?int $recruiterId): HiringRequest
    {
        $recruiter = $recruiterId ?? $request->recruiter_id;
        if ($recruiter === null || ! $this->requests->isActiveUser($recruiter)) {
            throw HiringException::invalidRecruiter();
        }
        $this->requests->transaction(function () use ($request, $recruiter): void {
            $fresh = $this->requests->lock($request->id);
            if ($fresh === null || $fresh->vacancy_id !== null) {
                return;
            }
            if (! in_array($fresh->status, [HiringRequestStatus::Approved, HiringRequestStatus::InProgress], true)) {
                throw HiringException::invalidStatus($fresh->status->value);
            }
            try {
                $vacancy = $this->vacancies->openOnBehalf(new VacancyData(VacancyDraft::from($fresh)), $recruiter);
            } catch (RecruitingException) {
                throw HiringException::noDefaultPipeline();
            }
            $this->requests->update($fresh, ['vacancy_id' => $vacancy->id, 'recruiter_id' => $recruiter, 'status' => HiringRequestStatus::InProgress->value]);
            $this->log->info('hiring.vacancy_created', ['id' => $fresh->id, 'vacancy' => $vacancy->id]);
        });

        return $this->reload($request);
    }

    /**
     * tz2 "в форме создания вакансии — поле «Заявка на вакансию»": link an existing vacancy to an approved request.
     *
     * @throws HiringException
     */
    public function linkVacancy(HiringRequest $request, int $vacancyId): HiringRequest
    {
        $this->assertStatus($request, [HiringRequestStatus::Approved]);
        if ($this->requests->vacancyLinked($vacancyId, $request->id)) {
            throw HiringException::vacancyTaken();
        }
        $vacancy = $this->vacancies->find($vacancyId);
        if ($vacancy->status !== VacancyStatus::Open || $vacancy->branch_id !== $request->branch_id) {
            throw HiringException::vacancyNotLinkable();
        }
        if (! $this->requests->transition($request, HiringRequestStatus::Approved, [
            'vacancy_id' => $vacancy->id, 'recruiter_id' => $vacancy->recruiter_id, 'status' => HiringRequestStatus::InProgress->value,
        ])) {
            throw HiringException::invalidStatus($request->status->value);
        }

        return $this->reload($request);
    }

    /**
     * Progress of linked vacancies: {vacancy_status, hired, headcount, percent} by request id.
     *
     * @param  iterable<HiringRequest>  $requests
     * @return array<int, array{vacancy_status: string|null, hired: int, headcount: int, percent: int}>
     */
    public function progress(iterable $requests): array
    {
        return $this->progress->of($requests);
    }

    /**
     * Moves the route forward: activates the next waiting step (notifies its approvers) or, when none is left,
     * approves the request and opens the vacancy (settings.auto_vacancy).
     */
    public function advance(HiringRequest $request, Carbon $now, ?int $recruiterId): HiringRequest
    {
        if ($request->status !== HiringRequestStatus::Pending || $request->currentApproval() !== null) {
            return $request;
        }
        $next = $request->approvals->first(static fn (HiringApproval $a): bool => $a->status === ApprovalStatus::Waiting);
        if ($next !== null) {
            $activated = $this->requests->transitionApproval($next, ApprovalStatus::Waiting, [
                'status' => ApprovalStatus::Pending->value,
                'activated_at' => $now,
                'due_at' => $next->sla_days === null ? null : $this->calendar->addWorkingDays($now, $next->sla_days, $request->branch_id),
            ]);
            if ($activated) {
                $this->notifier->notify($request, $next, $now);
            }

            return $this->reload($request);
        }
        if (! $this->requests->transition($request, HiringRequestStatus::Pending, ['status' => HiringRequestStatus::Approved->value, 'decided_at' => $now])) {
            return $this->reload($request);
        }
        $this->log->info('hiring.request_approved', ['id' => $request->id]);
        $this->notifier->decided($request, true);
        if ($this->requests->settings()->auto_vacancy) {
            $recruiter = $recruiterId ?? $request->requester_id;
            if ($recruiter !== null && $this->requests->isActiveUser($recruiter)) {
                return $this->createVacancy($this->reload($request), $recruiter);
            }
        }

        return $this->reload($request);
    }

    /** @return list<array{key: string, label: string, type: string, required: bool, options?: list<string>}> */
    public function fields(): array
    {
        return $this->requests->settings()->form_fields ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $given  keys actually sent (partial update)
     * @return array<string, mixed>
     *
     * @throws HiringException
     */
    private function attributes(array $data, bool $creating, ?array $given = null): array
    {
        return RequestAttributes::from($data, $creating, $given, fn (): array => $this->fields());
    }

    /**
     * @param  list<HiringRequestStatus>  $allowed
     *
     * @throws HiringException
     */
    private function assertStatus(HiringRequest $request, array $allowed): void
    {
        if (! in_array($request->status, $allowed, true)) {
            throw HiringException::invalidStatus($request->status->value);
        }
    }

    private function reload(HiringRequest $request): HiringRequest
    {
        return $this->requests->find($request->id) ?? $request;
    }
}
