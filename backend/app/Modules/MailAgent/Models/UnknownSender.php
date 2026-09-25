<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Models;

use App\Modules\MailAgent\Enums\ParserKey;
use App\Modules\MailAgent\Enums\SenderKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $email
 * @property string|null $sample_subject
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property int $count
 * @property SenderKind|null $suggested_kind
 * @property ParserKey|null $suggested_parser
 */
final class UnknownSender extends Model
{
    protected $fillable = ['email', 'sample_subject', 'first_seen_at', 'last_seen_at', 'count', 'suggested_kind', 'suggested_parser'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'count' => 'integer',
            'suggested_kind' => SenderKind::class,
            'suggested_parser' => ParserKey::class,
        ];
    }
}
