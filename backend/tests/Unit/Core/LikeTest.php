<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Modules\Core\Support\Database\Like;
use PHPUnit\Framework\TestCase;

/** LIKE patterns from user input: wildcards are literal, for both escape characters in use. */
final class LikeTest extends TestCase
{
    public function test_backslash_escaping_is_the_same_as_the_addcslashes_it_replaced(): void
    {
        foreach (['plain', '50%', 'a_b', 'back\\slash', '%_\\', 'Іван_100%', ''] as $value) {
            $this->assertSame(addcslashes($value, '%_\\'), Like::escape($value), $value);
            $this->assertSame('%'.addcslashes($value, '%_\\').'%', Like::contains($value), $value);
        }
    }

    public function test_portable_escaping_is_the_same_as_the_bang_replacement_it_replaced(): void
    {
        foreach (['plain', '50%', 'a_b', 'wow!', '!%_', ''] as $value) {
            $legacy = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
            $this->assertSame($legacy, Like::escape($value, Like::PORTABLE), $value);
            $this->assertSame('%'.$legacy.'%', Like::contains($value, Like::PORTABLE), $value);
            $this->assertSame($legacy.'%', Like::startsWith($value, Like::PORTABLE), $value);
        }
    }

    public function test_the_escape_character_itself_is_escaped_first(): void
    {
        $this->assertSame('a!!!%', Like::escape('a!%', Like::PORTABLE));
        // a\% → a\\\% : the backslash doubled, then the percent escaped.
        $this->assertSame('a\\\\\\%', Like::escape('a\\%'));
        $this->assertSame('x\\_y%', Like::startsWith('x_y'));
    }
}
