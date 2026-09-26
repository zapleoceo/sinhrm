<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Models;

use App\Modules\MailAgent\Enums\ParserKey;
use App\Modules\MailAgent\Enums\SenderKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $pattern "a@b.c" or "@b.c"
 * @property SenderKind $kind
 * @property ParserKey|null $parser
 * @property int|null $created_by null for rules created by AI
 * @property string $source manual | ai
 * @property float|null $ai_confidence
 * @property string|null $prompt_version
 * @property int|null $ai_request_id
 * @property int $hits
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SenderRule extends Model
{
    public const string SOURCE_MANUAL = 'manual';

    public const string SOURCE_AI = 'ai';

    protected $fillable = [
        'pattern', 'kind', 'parser', 'created_by', 'hits', 'last_seen_at', 'source', 'ai_confidence', 'prompt_version', 'ai_request_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['hits' => 0, 'source' => self::SOURCE_MANUAL];

    public function isDomain(): bool
    {
        return str_starts_with($this->pattern, '@');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => SenderKind::class,
            'parser' => ParserKey::class,
            'hits' => 'integer',
            'last_seen_at' => 'datetime',
            'ai_confidence' => 'float',
        ];
    }
}
