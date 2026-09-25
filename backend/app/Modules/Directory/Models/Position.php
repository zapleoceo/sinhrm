<?php

declare(strict_types=1);

namespace App\Modules\Directory\Models;

use App\Modules\Directory\Database\Factories\PositionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

final class Position extends DictionaryItem
{
    /** @use HasFactory<PositionFactory> */
    use HasFactory;

    protected $table = 'positions';

    protected static function newFactory(): PositionFactory
    {
        return PositionFactory::new();
    }
}
