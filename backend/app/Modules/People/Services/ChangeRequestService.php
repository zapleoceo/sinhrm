<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Models\User;
use App\Modules\People\Contracts\ChangeRequestRepository;
use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\Enums\ChangeableField;
use App\Modules\People\Enums\ChangeRequestStatus;
use App\Modules\People\Exceptions\PeopleException;
use App\Modules\People\Models\Employee;
use App\Modules\People\Models\EmployeeChangeRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Self-service change requests: the employee proposes new values for whitelisted fields (ChangeableField);
 * an admin or a manager above the employee approves (values are applied) or rejects.
 */
final readonly class ChangeRequestService
{
    public function __construct(
        private ChangeRequestRepository $requests,
        private EmployeeRepository $employees,
        private LoggerInterface $log,
    ) {}

    /**
     * Admin: all; others: own requests and requests of employees below them.
     *
     * @return LengthAwarePaginator<int, EmployeeChangeRequest>
     */
    public function list(PeopleContext $ctx, ?ChangeRequestStatus $status, ?int $employeeId, int $perPage): LengthAwarePaginator
    {
        return $this->requests->paginate($ctx->visibleIds(), $status, $employeeId, $perPage);
    }

    /** @throws ModelNotFoundException<EmployeeChangeRequest> */
    public function find(int $id): EmployeeChangeRequest
    {
        return $this->requests->find($id) ?? throw (new ModelNotFoundException)->setModel(EmployeeChangeRequest::class, [$id]);
    }

    /** @param  array<string, string|null>  $changes */
    public function submit(User $actor, Employee $employee, array $changes, ?string $comment): EmployeeChangeRequest
    {
        $request = $this->requests->create([
            'employee_id' => $employee->id,
            'requested_by' => $actor->id,
            'changes' => $changes,
            'status' => ChangeRequestStatus::Pending->value,
            'comment' => $comment,
        ]);
        $this->log->info('people.change_requested', ['id' => $request->id, 'employee' => $employee->id, 'fields' => array_keys($changes)]);

        return $this->find($request->id);
    }

    /** @throws PeopleException forbidden | already_decided */
    public function decide(User $actor, PeopleContext $ctx, EmployeeChangeRequest $request, bool $approve, ?string $comment): EmployeeChangeRequest
    {
        if (! $ctx->canDecideFor($request->employee_id)) {
            throw PeopleException::forbidden();
        }
        $status = $approve ? ChangeRequestStatus::Approved : ChangeRequestStatus::Rejected;
        $this->employees->transaction(function () use ($actor, $request, $status, $approve, $comment): void {
            $decided = $this->requests->decideIfPending($request, [
                'status' => $status->value,
                'decided_by' => $actor->id,
                'decided_at' => Carbon::now(),
                'decision_comment' => $comment,
            ]);
            if (! $decided) {
                throw PeopleException::alreadyDecided();
            }
            if ($approve) {
                // Whitelist again at apply time: stored JSON is never trusted to hold only allowed keys.
                $this->employees->update($request->employee, array_intersect_key($request->changes, array_flip(ChangeableField::values())));
            }
        });
        $this->log->info('people.change_decided', ['id' => $request->id, 'status' => $status->value, 'by' => $actor->id]);

        return $this->find($request->id);
    }
}
