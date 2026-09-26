<?php

declare(strict_types=1);

namespace App\Modules\Assets\Enums;

/** Where an asset is. "assigned" is set only by assign(); returning sets one of the others. */
enum AssetStatus: string
{
    case InStock = 'in_stock';
    case Assigned = 'assigned';
    case Repair = 'repair';
    case WrittenOff = 'written_off';

    /** @return list<string> statuses an asset may take when it comes back */
    public static function returnValues(): array
    {
        return [self::InStock->value, self::Repair->value, self::WrittenOff->value];
    }
}
