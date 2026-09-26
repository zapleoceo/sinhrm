<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Models;

use App\Modules\Recruiting\Enums\AcquisitionChannelType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Where candidates come from (tz3 dictionary). Deactivated, never deleted (candidates keep the link).
 *
 * @property int $id
 * @property string $code technical name, lowercase, unique
 * @property string $name
 * @property AcquisitionChannelType $type
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, ChannelUtmRule> $utmRules
 * @property-read Collection<int, AcquisitionChannelCost> $costs
 */
final class AcquisitionChannel extends Model
{
    protected $fillable = ['code', 'name', 'type', 'active'];

    /** @var array<string, mixed> */
    protected $attributes = ['type' => 'other', 'active' => true];

    /** @return HasMany<ChannelUtmRule, $this> */
    public function utmRules(): HasMany
    {
        return $this->hasMany(ChannelUtmRule::class, 'channel_id');
    }

    /** @return HasMany<AcquisitionChannelCost, $this> */
    public function costs(): HasMany
    {
        return $this->hasMany(AcquisitionChannelCost::class, 'channel_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => AcquisitionChannelType::class, 'active' => 'boolean'];
    }
}
