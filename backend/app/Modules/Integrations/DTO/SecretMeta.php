<?php

declare(strict_types=1);

namespace App\Modules\Integrations\DTO;

use Illuminate\Support\Carbon;

/** What the API may say about a secret: whether it is set, when it changed and a short masked tail. */
final readonly class SecretMeta
{
    public function __construct(public bool $isSet, public ?Carbon $updatedAt = null, public ?string $masked = null) {}

    /** "••••1234": at most 4 trailing chars, and never more than a quarter of the value. */
    public static function mask(string $value): string
    {
        $visible = min(4, intdiv(mb_strlen($value), 4));

        return '••••'.($visible > 0 ? mb_substr($value, -$visible) : '');
    }
}
