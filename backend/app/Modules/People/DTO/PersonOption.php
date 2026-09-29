<?php

declare(strict_types=1);

namespace App\Modules\People\DTO;

use App\Models\User;
use App\Modules\People\Models\Employee;

/**
 * One row of the person picker: directory-level data only. Never e-mail, phone, salary, gender or birth date.
 * id is an employee id, or a user id for the `users` scope.
 */
final readonly class PersonOption
{
    public function __construct(
        public int $id,
        public string $fullName,
        public ?string $position,
        public ?string $department,
        public ?string $avatarUrl,
        public bool $terminated = false,
        public ?string $terminatedAt = null,
    ) {}

    public static function ofEmployee(Employee $employee): self
    {
        return new self(
            $employee->id,
            $employee->full_name,
            $employee->position?->name,
            $employee->department?->name,
            $employee->avatar_url,
            $employee->isTerminated(),
            $employee->isTerminated() ? $employee->fired_at?->toDateString() : null,
        );
    }

    public static function ofUser(User $user): self
    {
        return new self($user->id, $user->name, null, null, $user->avatar_url);
    }

    /** @return array{id: int, full_name: string, position: string|null, department: string|null, avatar_url: string|null, terminated: bool, terminated_at: string|null} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->fullName,
            'position' => $this->position,
            'department' => $this->department,
            'avatar_url' => $this->avatarUrl,
            'terminated' => $this->terminated,
            'terminated_at' => $this->terminatedAt,
        ];
    }
}
