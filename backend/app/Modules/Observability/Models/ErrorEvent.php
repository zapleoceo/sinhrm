<?php

declare(strict_types=1);

namespace App\Modules\Observability\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $fingerprint
 * @property string $source
 * @property string $exception_class
 * @property string $message
 * @property string|null $file
 * @property int|null $line
 * @property string|null $route
 * @property int|null $last_user_id
 * @property int $count
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $resolved_at
 */
final class ErrorEvent extends Model
{
    protected $table = 'error_events';

    protected $guarded = ['id'];

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'exception_class' => $this->exception_class,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'route' => $this->route,
            'last_user_id' => $this->last_user_id,
            'count' => $this->count,
            'first_seen_at' => $this->first_seen_at->toIso8601String(),
            'last_seen_at' => $this->last_seen_at->toIso8601String(),
            'resolved' => $this->resolved_at !== null,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
        ];
    }

    protected function casts(): array
    {
        return [
            'line' => 'integer',
            'last_user_id' => 'integer',
            'count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
