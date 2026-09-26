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
 * @property string|null $ai_status pending|done|failed; null = AI not asked
 * @property SenderKind|null $ai_kind
 * @property ParserKey|null $ai_parser
 * @property float|null $ai_confidence
 * @property array{full_name: string|null, phone: string|null, email: string|null, vacancy_title: string|null}|null $ai_extracted
 * @property int|null $ai_request_id
 */
final class UnknownSender extends Model
{
    protected $fillable = [
        'email', 'sample_subject', 'first_seen_at', 'last_seen_at', 'count', 'suggested_kind', 'suggested_parser',
        'ai_status', 'ai_kind', 'ai_parser', 'ai_confidence', 'ai_extracted', 'ai_request_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'count' => 'integer',
            'suggested_kind' => SenderKind::class,
            'suggested_parser' => ParserKey::class,
            'ai_kind' => SenderKind::class,
            'ai_parser' => ParserKey::class,
            'ai_confidence' => 'float',
            'ai_extracted' => 'array',
        ];
    }
}
