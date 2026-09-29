<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Models\User;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\People\Contracts\PickerUserRepository;
use App\Modules\People\DTO\EmployeeFilter;
use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\DTO\PersonOption;
use App\Modules\People\Enums\PickerScope;
use App\Modules\People\Models\Employee;
use App\Modules\Users\Contracts\UserAdminRepository;
use App\Modules\Users\DTO\UserFilter;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Person picker (search by name, id → name). Reuses the directory query (EmployeeService::list + PeopleScope),
 * so a user finds exactly the people the People directory shows them:
 * - employees — the directory (working people; HR may add terminated);
 * - subordinates — the caller's managed subtree (HR: everyone);
 * - users — active system users, HR staff only.
 */
final readonly class PersonPicker
{
    public function __construct(
        private EmployeeService $employees,
        private PeopleScope $scope,
        private UserAdminRepository $users,
        private PickerUserRepository $pickerUsers,
    ) {}

    /**
     * @return list<PersonOption>
     *
     * @throws AuthorizationException
     */
    public function search(User $actor, PickerScope $scope, string $q, int $limit, bool $includeTerminated): array
    {
        $ctx = $this->scope->for($actor);
        if ($scope === PickerScope::Users) {
            $this->assertHr($ctx);
            $users = $this->users->paginate(new UserFilter(q: $q, status: UserStatus::Active, perPage: $limit))->items();

            return array_values(array_map(static fn (User $u): PersonOption => PersonOption::ofUser($u), $users));
        }
        $filter = new EmployeeFilter(q: $q, perPage: $limit, anyStatus: $includeTerminated && $ctx->admin);
        if ($scope === PickerScope::Subordinates && ! $ctx->admin) {
            $filter = $filter->restrictedTo($ctx->subtreeIds);
        }

        return $this->options($this->employees->list($ctx, $filter)->items());
    }

    /**
     * Names for saved ids. Unknown and invisible ids are simply absent. A terminated employee resolves only for
     * HR and managers above them (the same rule as the profile, EmployeeService::findVisible).
     *
     * @param  list<int>  $ids
     * @return list<PersonOption>
     *
     * @throws AuthorizationException
     */
    public function lookup(User $actor, PickerScope $scope, array $ids): array
    {
        $ctx = $this->scope->for($actor);
        if ($scope === PickerScope::Users) {
            $this->assertHr($ctx);

            return array_values($this->pickerUsers->activeByIds($ids)->map(static fn (User $u): PersonOption => PersonOption::ofUser($u))->all());
        }
        $rows = $this->employees->list($ctx, new EmployeeFilter(perPage: max(1, count($ids)), onlyIds: $ids, anyStatus: true))->items();

        return $this->options(array_filter(
            $rows,
            static fn (Employee $e): bool => ! $e->isTerminated() || $ctx->admin || $ctx->isAbove($e->id),
        ));
    }

    /**
     * @param  array<int, Employee>  $employees
     * @return list<PersonOption>
     */
    private function options(array $employees): array
    {
        return array_values(array_map(static fn (Employee $e): PersonOption => PersonOption::ofEmployee($e), $employees));
    }

    /** @throws AuthorizationException */
    private function assertHr(PeopleContext $ctx): void
    {
        if (! $ctx->admin) {
            throw new AuthorizationException;
        }
    }
}
