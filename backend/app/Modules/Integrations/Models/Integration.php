<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Models;

use App\Modules\Integrations\Enums\IntegrationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property IntegrationStatus $status
 * @property array<string, mixed> $settings non-secret config only
 * @property Carbon|null $last_checked_at
 * @property string|null $last_error scrubbed, never contains secrets
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Integration extends Model
{
    protected $table = 'integrations';

    protected $fillable = ['key', 'status', 'settings', 'last_checked_at', 'last_error'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'off',
        'settings' => '{}',
    ];

    /** @return HasMany<IntegrationSecret, $this> */
    public function secrets(): HasMany
    {
        return $this->hasMany(IntegrationSecret::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => IntegrationStatus::class,
            'settings' => 'array',
            'last_checked_at' => 'datetime',
        ];
    }
}
