<?php

declare(strict_types=1);

namespace Tests\Feature\Recruiting;

use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Recruiting\Services\StalenessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DemoCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_synthetic_data_once(): void
    {
        $this->artisan('recruiting:demo')->assertSuccessful();

        $this->assertSame(40, Candidate::query()->count());
        $this->assertSame(6, Touchpoint::query()->whereNull('candidate_id')->count());
        $channels = Touchpoint::query()->distinct()->pluck('channel')->map(fn ($c) => $c->value)->sort()->values()->all();
        $this->assertSame(['call', 'email', 'meeting', 'note', 'system', 'telegram', 'viber', 'whatsapp'], $channels);
        $this->assertTrue(Touchpoint::query()->where('via_product', false)->exists());
        $stale = Application::query()->with('candidate')->get()->filter(fn (Application $a): bool => StalenessService::isStale($a));
        $this->assertGreaterThan(0, $stale->count());
        $this->assertSame(0, Candidate::query()->where('email', 'not like', '%@example.test')->count());

        $this->artisan('recruiting:demo')->expectsOutputToContain('already present')->assertSuccessful();
        $this->assertSame(40, Candidate::query()->count());
    }

    public function test_refuses_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->artisan('recruiting:demo')->assertFailed();
        $this->assertSame(0, Candidate::query()->count());
    }
}
