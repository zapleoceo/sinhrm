<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Models;

use App\Modules\Integrations\Enums\LogLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Audit trail of an integration. Context holds names and ids only — never secret values.
 *
 * @property int $id
 * @property int $integration_id
 * @property LogLevel $level
 * @property string $message
 * @property array<string, mixed>|null $context
 * @property Carbon|null $created_at
 */
final class IntegrationLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'integration_logs';

    protected $fillable = ['integration_id', 'level', 'message', 'context'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'level' => LogLevel::class,
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
