<?php

declare(strict_types=1);

namespace App\Modules\Reports\Datasets;

use App\Modules\Reports\Contracts\Dataset;
use App\Modules\Reports\DTO\ScopedContext;

/** The asset register (admins, like the inventory itself). */
final class AssetsDataset implements Dataset
{
    public function key(): string
    {
        return 'assets';
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->isAdmin();
    }

    public function columns(): array
    {
        return [
            'id' => ['expr' => 'x.id', 'type' => self::NUMBER],
            'inventory_number' => ['expr' => 'x.inventory_number', 'type' => self::STRING],
            'name' => ['expr' => 'x.name', 'type' => self::STRING],
            'serial' => ['expr' => 'x.serial', 'type' => self::STRING],
            'type' => ['expr' => 'y.name', 'type' => self::STRING],
            'status' => ['expr' => 'x.status', 'type' => self::STRING],
            'cost' => ['expr' => 'x.cost', 'type' => self::NUMBER],
            'purchased_at' => ['expr' => 'x.purchased_at', 'type' => self::DATE],
            'holder' => ['expr' => 'e.full_name', 'type' => self::STRING],
        ];
    }
}
