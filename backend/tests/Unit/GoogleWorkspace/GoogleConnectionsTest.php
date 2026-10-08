<?php

declare(strict_types=1);

namespace Tests\Unit\GoogleWorkspace;

use App\Modules\GoogleWorkspace\Contracts\GoogleConnections;
use App\Modules\GoogleWorkspace\Services\GoogleConnectionStore;
use Tests\TestCase;

/** Other modules read the Google connection state through GoogleConnections, bound to GoogleConnectionStore. */
final class GoogleConnectionsTest extends TestCase
{
    public function test_the_contract_is_bound_to_the_connection_store(): void
    {
        $this->assertInstanceOf(GoogleConnectionStore::class, $this->app->make(GoogleConnections::class));
    }
}
