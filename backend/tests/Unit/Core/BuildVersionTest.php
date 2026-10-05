<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Modules\Core\Support\BuildVersion;
use PHPUnit\Framework\TestCase;

final class BuildVersionTest extends TestCase
{
    public function test_only_full_lowercase_git_sha_is_exposed(): void
    {
        $sha = str_repeat('a', 40);
        self::assertSame($sha, BuildVersion::fromSha($sha));
        foreach ([null, false, [], 123, '', 'secret', str_repeat('A', 40), $sha."\n", str_repeat('a', 41)] as $invalid) {
            self::assertSame('dev', BuildVersion::fromSha($invalid));
        }
    }
}
