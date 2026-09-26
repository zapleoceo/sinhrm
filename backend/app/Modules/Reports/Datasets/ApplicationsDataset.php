<?php

declare(strict_types=1);

namespace App\Modules\Reports\Datasets;

use App\Modules\Reports\Contracts\Dataset;
use App\Modules\Reports\DTO\ScopedContext;

/** Applications of vacancies in the user's branches (Recruiting scope). Candidate contacts are PII. */
final class ApplicationsDataset implements Dataset
{
    public function key(): string
    {
        return 'applications';
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->user->isActive();
    }

    public function columns(): array
    {
        return [
            'id' => ['expr' => 'a.id', 'type' => self::NUMBER],
            'candidate' => ['expr' => 'c.full_name', 'type' => self::STRING],
            'candidate_email' => ['expr' => 'c.email', 'type' => self::STRING, 'pii' => true],
            'candidate_phone' => ['expr' => 'c.phone', 'type' => self::STRING, 'pii' => true],
            'source' => ['expr' => 'c.source', 'type' => self::STRING],
            'vacancy' => ['expr' => 'v.title', 'type' => self::STRING],
            'branch' => ['expr' => 'b.name', 'type' => self::STRING],
            'stage' => ['expr' => 's.name', 'type' => self::STRING],
            'status' => ['expr' => 'a.status', 'type' => self::STRING],
            'reject_reason' => ['expr' => 'r.name', 'type' => self::STRING],
            'created_at' => ['expr' => 'a.created_at', 'type' => self::DATE],
            'closed_at' => ['expr' => 'a.closed_at', 'type' => self::DATE],
        ];
    }
}
