<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, PipelineStage> $stages
 */
final class Pipeline extends Model
{
    protected $fillable = ['name', 'is_default'];

    /** @return HasMany<PipelineStage, $this> */
    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class)->orderBy('position');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }
}
