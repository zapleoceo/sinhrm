<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Contracts\UserRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Auth\Contracts\UserRepository lookups other modules use instead of querying users themselves. */
final class UserRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_names_by_ids_maps_known_users_and_skips_missing_ones(): void
    {
        $ann = User::factory()->create(['name' => 'Ann Example']);
        $bob = User::factory()->create(['name' => 'Bob Example']);

        $names = $this->app->make(UserRepository::class)->namesByIds([$bob->id, $ann->id, $bob->id + 1000]);

        $this->assertSame([$ann->id => 'Ann Example', $bob->id => 'Bob Example'], $this->sorted($names));
        $this->assertSame([], $this->app->make(UserRepository::class)->namesByIds([]));
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    private function sorted(array $names): array
    {
        ksort($names);

        return $names;
    }
}
