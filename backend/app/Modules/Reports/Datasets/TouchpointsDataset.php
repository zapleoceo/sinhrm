<?php

declare(strict_types=1);

namespace App\Modules\Reports\Datasets;

use App\Modules\Reports\Contracts\Dataset;
use App\Modules\Reports\DTO\ScopedContext;

/** Touches with candidates (Recruiting scope: branch or own). Message bodies are never exposed. */
final class TouchpointsDataset implements Dataset
{
    public function key(): string
    {
        return 'touchpoints';
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->user->isActive();
    }

    public function columns(): array
    {
        return [
            'id' => ['expr' => 't.id', 'type' => self::NUMBER],
            'channel' => ['expr' => 't.channel', 'type' => self::STRING],
            'direction' => ['expr' => 't.direction', 'type' => self::STRING],
            'author' => ['expr' => 'u.name', 'type' => self::STRING],
            'candidate' => ['expr' => 'c.full_name', 'type' => self::STRING],
            'branch' => ['expr' => 'b.name', 'type' => self::STRING],
            'via_product' => ['expr' => 't.via_product', 'type' => self::STRING],
            'occurred_at' => ['expr' => 't.occurred_at', 'type' => self::DATE],
        ];
    }
}
