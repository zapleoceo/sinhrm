<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

/** Only public, immutable commit identifiers may be returned by health. */
final class BuildVersion
{
    public static function fromSha(mixed $sha): string
    {
        return is_string($sha) && preg_match('/\A[0-9a-f]{40}\z/', $sha) === 1 ? $sha : 'dev';
    }
}
