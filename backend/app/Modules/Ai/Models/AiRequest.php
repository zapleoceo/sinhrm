<?php

declare(strict_types=1);

namespace App\Modules\Ai\Models;

use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Enums\AiRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One AI request (all attempts). Never stores the prompt or the answer text — only ids, counters and codes.
 *
 * @property int $id
 * @property AiPurpose $purpose
 * @property string|null $subject_type e.g. "touchpoint", "unknown_sender", "screening"
 * @property int|null $subject_id
 * @property array<string, int|string|bool>|null $meta ids/flags only (e.g. script_version_id), never personal data
 * @property string $provider
 * @property string|null $capability
 * @property string|null $job_id
 * @property AiRequestStatus $status
 * @property int $attempts
 * @property string $prompt_version
 * @property int $tokens_in
 * @property int $tokens_out
 * @property int $tokens_cached
 * @property float $cost_usd
 * @property string|null $model
 * @property string|null $error
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AiRequest extends Model
{
    protected $table = 'ai_requests';

    protected $fillable = [
        'purpose', 'subject_type', 'subject_id', 'meta', 'provider', 'capability', 'job_id', 'status', 'attempts', 'prompt_version',
        'tokens_in', 'tokens_out', 'tokens_cached', 'cost_usd', 'model', 'error', 'completed_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'attempts' => 1,
        'tokens_in' => 0,
        'tokens_out' => 0,
        'tokens_cached' => 0,
        'cost_usd' => 0,
    ];

    public function metaInt(string $key): ?int
    {
        $value = $this->meta[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purpose' => AiPurpose::class,
            'status' => AiRequestStatus::class,
            'meta' => 'array',
            'subject_id' => 'integer',
            'attempts' => 'integer',
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'tokens_cached' => 'integer',
            'cost_usd' => 'float',
            'completed_at' => 'datetime',
        ];
    }
}
