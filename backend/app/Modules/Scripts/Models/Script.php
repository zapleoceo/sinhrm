<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Models;

use App\Modules\Scripts\Enums\ScriptChannel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property ScriptChannel $channel
 * @property int|null $active_version_id
 * @property bool $archived
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ScriptVersion|null $activeVersion
 * @property-read ScriptVersion|null $draft
 * @property-read Collection<int, ScriptVersion> $versions
 */
final class Script extends Model
{
    protected $fillable = ['name', 'channel', 'active_version_id', 'archived'];

    /** @var array<string, mixed> */
    protected $attributes = ['archived' => false];

    /** @return BelongsTo<ScriptVersion, $this> */
    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(ScriptVersion::class, 'active_version_id');
    }

    /**
     * The editable draft (at most one per script).
     *
     * @return HasOne<ScriptVersion, $this>
     */
    public function draft(): HasOne
    {
        return $this->hasOne(ScriptVersion::class)->whereNull('published_at');
    }

    /** @return HasMany<ScriptVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ScriptVersion::class)->orderByDesc('version');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['channel' => ScriptChannel::class, 'archived' => 'boolean'];
    }
}
