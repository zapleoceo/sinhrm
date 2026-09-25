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
 * @property int|null $created_by
 * @property int $hits
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SenderRule extends Model
{
    protected $fillable = ['pattern', 'kind', 'parser', 'created_by', 'hits', 'last_seen_at'];

    /** @var array<string, mixed> */
    protected $attributes = ['hits' => 0];

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
        ];
    }
}
