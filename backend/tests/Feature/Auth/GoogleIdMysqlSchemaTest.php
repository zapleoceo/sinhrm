<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MysqlSchemaAssertions;
use Tests\TestCase;

/** users.google_id is an opaque id: binary collation on MySQL 8.4 (ADR 0010), unique, case-sensitive. */
final class GoogleIdMysqlSchemaTest extends TestCase
{
    use MysqlSchemaAssertions, RefreshDatabase;

    public function test_google_id_is_binary_unique_and_case_sensitive(): void
    {
        $this->assertColumnCollation('users', 'google_id', 'utf8mb4_bin');
        $this->assertSame(['google_id'], $this->indexParts('users', 'users_google_id_unique'));

        $upper = User::factory()->create(['google_id' => 'Gid-A']);
        $lower = User::factory()->create(['google_id' => 'Gid-a']);
        $this->assertSame($lower->id, User::query()->where('google_id', 'Gid-a')->value('id'));
        $this->assertSame($upper->id, User::query()->where('google_id', 'Gid-A')->value('id'));

        $this->expectException(UniqueConstraintViolationException::class);
        User::factory()->create(['google_id' => 'Gid-A']);
    }
}
