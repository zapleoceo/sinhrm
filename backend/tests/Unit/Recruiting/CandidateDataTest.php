<?php

declare(strict_types=1);

namespace Tests\Unit\Recruiting;

use App\Modules\Recruiting\DTO\CandidateData;
use PHPUnit\Framework\TestCase;

/** CandidateData::fromArray — the loose import row → DTO (Sheets, job boards). */
final class CandidateDataTest extends TestCase
{
    public function test_a_name_longer_than_the_column_is_cut_to_255_characters(): void
    {
        $data = CandidateData::fromArray(['full_name' => '  '.str_repeat('Ґ', 300).'  ']);

        $this->assertSame(str_repeat('Ґ', CandidateData::MAX_NAME), $data->fullName);
    }

    public function test_a_short_name_is_only_trimmed_and_an_empty_one_is_null(): void
    {
        $this->assertSame('Олена Тест', CandidateData::fromArray(['full_name' => ' Олена Тест '])->fullName);
        $this->assertNull(CandidateData::fromArray(['full_name' => '   '])->fullName);
        $this->assertNull(CandidateData::fromArray([])->fullName);
    }

    public function test_a_cut_never_ends_with_a_space(): void
    {
        $name = CandidateData::fromArray(['full_name' => str_repeat('a', 254).' b'])->fullName;

        $this->assertSame(str_repeat('a', 254), $name);
    }
}
