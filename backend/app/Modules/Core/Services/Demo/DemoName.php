<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Demo;

/**
 * Names of demo rows: the marker goes at the END ("Бондаренко Андрій [ТЕСТ]"), so sorted lists do not start with a
 * wall of "[ТЕСТ] [ТЕСТ] …". The one demo branch is named BRANCH (no marker). LEGACY_PREFIX is the old style at the start,
 * used only to find rows left by earlier fills (DemoLegacy).
 */
final class DemoName
{
    public const string MARK = '[ТЕСТ]';

    public const string SUFFIX = ' '.self::MARK;

    public const string LEGACY_PREFIX = self::MARK.' ';

    public const string BRANCH = 'Тестовий філіал';

    public static function tag(string $name): string
    {
        return $name.self::SUFFIX;
    }

    public static function untag(string $name): string
    {
        return str_ends_with($name, self::SUFFIX) ? substr($name, 0, -strlen(self::SUFFIX)) : $name;
    }
}
