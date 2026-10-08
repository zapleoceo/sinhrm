<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Transfer;

use App\Modules\Core\Contracts\CollationKeys;
use App\Modules\Core\Services\Transfer\CollisionFinder;
use Normalizer;
use PHPUnit\Framework\TestCase;

/** The real keys come from MySQL WEIGHT_STRING (feature test in the mysql-data-transfer workflow); here a stand-in. */
final class CollisionFinderTest extends TestCase
{
    public function test_case_and_accent_variants_collide_and_distinct_values_do_not(): void
    {
        $values = ['1' => 'a@x.test', '2' => 'A@x.test', '3' => 'jose', '4' => 'josé', '5' => 'Ганна', '6' => 'b@x.test'];
        $keys = $this->accentAndCaseInsensitive()->keys('utf8mb4_0900_ai_ci', array_values($values));
        $finder = new CollisionFinder;
        foreach (array_keys($values) as $i => $id) {
            $finder->add($keys[$i], (string) $id);
        }

        $this->assertSame([['1', '2'], ['3', '4']], $finder->collisions());
    }

    public function test_no_collisions_without_equal_keys(): void
    {
        $finder = new CollisionFinder;
        $finder->add('k1', '1');
        $finder->add('k2', '2');

        $this->assertSame([], $finder->collisions());
    }

    private function accentAndCaseInsensitive(): CollationKeys
    {
        return new class implements CollationKeys
        {
            public function keys(string $collation, array $values): array
            {
                return array_map(
                    fn (string $v): string => mb_strtolower((string) preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($v, Normalizer::FORM_D))),
                    $values,
                );
            }
        };
    }
}
