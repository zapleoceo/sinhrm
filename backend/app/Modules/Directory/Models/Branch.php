<?php

declare(strict_types=1);

namespace App\Modules\Directory\Models;

use App\Modules\Directory\Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $city_id
 * @property-read City|null $city
 */
final class Branch extends DictionaryItem
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    protected $table = 'branches';

    protected $fillable = ['name', 'status', 'city_id'];

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    protected static function newFactory(): BranchFactory
    {
        return BranchFactory::new();
    }
}
