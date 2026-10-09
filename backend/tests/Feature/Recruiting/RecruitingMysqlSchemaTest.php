<?php

declare(strict_types=1);

namespace Tests\Feature\Recruiting;

use App\Modules\Recruiting\Models\Candidate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MysqlSchemaAssertions;
use Tests\TestCase;

/**
 * Recruiting schema on MySQL 8.4 (ADR 0010): opaque ids in binary collation; candidate contacts unique, NULL repeatable
 * (a plain MySQL unique index — MySQL never collides NULLs).
 */
final class RecruitingMysqlSchemaTest extends TestCase
{
    use MysqlSchemaAssertions, RefreshDatabase;

    public function test_opaque_identifiers_use_binary_collation(): void
    {
        $this->assertColumnCollation('touchpoints', 'external_id', 'utf8mb4_bin');
        $this->assertColumnCollation('candidate_profile_urls', 'url', 'utf8mb4_bin');
        $this->assertSame(['channel', 'external_id'], $this->indexParts('touchpoints', 'touchpoints_channel_external_id_unique'));
    }

    public function test_contact_unique_indexes_allow_many_nulls_and_reject_a_duplicate(): void
    {
        foreach (['phone', 'email', 'telegram_username'] as $column) {
            $this->assertSame([$column], $this->indexParts('candidates', "candidates_{$column}_unique"));
            $this->assertSame([], $this->indexParts('candidates', "candidates_{$column}_index"));
        }
        Candidate::factory()->count(2)->create(['phone' => null, 'email' => null, 'telegram_username' => null]);
        Candidate::factory()->create(['telegram_username' => 'dup_contact']);

        $this->expectException(UniqueConstraintViolationException::class);
        Candidate::factory()->create(['telegram_username' => 'dup_contact']);
    }
}
