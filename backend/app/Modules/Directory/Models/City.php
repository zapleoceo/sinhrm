<?php

declare(strict_types=1);

namespace App\Modules\Directory\Models;

use App\Modules\Directory\Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

final class City extends DictionaryItem
{
    /** @use HasFactory<CityFactory> */
    use HasFactory;

    protected $table = 'cities';

    protected static function newFactory(): CityFactory
    {
        return CityFactory::new();
    }
}
