<?php

declare(strict_types=1);

namespace App\Modules\Core\DTO;

use App\Modules\Core\Enums\DataSubjectType;

/** A person identified by the owner module's id: candidates.id (Recruiting) or employees.id (People). */
final readonly class DataSubject
{
    public function __construct(public DataSubjectType $type, public int $id) {}

    public static function candidate(int $id): self
    {
        return new self(DataSubjectType::Candidate, $id);
    }

    public static function employee(int $id): self
    {
        return new self(DataSubjectType::Employee, $id);
    }
}
