<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $trigger manual|cron
 * @property int|null $user_id
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property int|null $cursor_ms
 * @property array<string, int>|null $counts
 * @property string|null $error
 */
final class MailSyncRun extends Model
{
    protected $fillable = ['trigger', 'user_id', 'started_at', 'finished_at', 'cursor_ms', 'counts', 'error'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'cursor_ms' => 'integer', 'counts' => 'array'];
    }
}
