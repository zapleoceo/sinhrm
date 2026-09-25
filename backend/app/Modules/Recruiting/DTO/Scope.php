<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\DTO;

/**
 * What a user may see in Recruiting. branchIds null = everything (superadmin/admin); otherwise vacancies of these
 * branches, their applications and candidates, plus candidates the user owns/created and inbox messages they authored.
 */
final readonly class Scope
{
    /** @param  list<int>|null  $branchIds */
    public function __construct(
        public int $userId,
        public ?array $branchIds,
    ) {}

    public function isUnrestricted(): bool
    {
        return $this->branchIds === null;
    }

    public function allowsBranch(int $branchId): bool
    {
        return $this->branchIds === null || in_array($branchId, $this->branchIds, true);
    }
}
