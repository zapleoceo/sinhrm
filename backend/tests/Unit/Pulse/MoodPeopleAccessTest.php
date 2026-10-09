<?php

declare(strict_types=1);

namespace Tests\Unit\Pulse;

use App\Models\User;
use App\Modules\People\Contracts\PeopleAccess;
use App\Modules\Pulse\Contracts\MoodRepository;
use App\Modules\Pulse\Models\MoodSetting;
use App\Modules\Pulse\Services\MoodService;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use Tests\Support\FakePeopleAccess;
use Tests\TestCase;

/** The mood check-in finds the employee through People's PeopleAccess contract; without a card nobody is asked (no DB). */
final class MoodPeopleAccessTest extends TestCase
{
    public function test_a_user_without_an_employee_card_is_not_asked(): void
    {
        $this->app->instance(PeopleAccess::class, new FakePeopleAccess);
        /** @var MoodRepository&MockInterface $mood */
        $mood = $this->mock(MoodRepository::class);
        $mood->expects('settings')->andReturn(new MoodSetting(['weekdays' => [1, 2, 3, 4, 5], 'question' => 'How are you?', 'required' => false]));
        $mood->expects('forDay')->never();
        $user = new User;
        $user->id = 3;

        $today = $this->app->make(MoodService::class)->today($user, Carbon::parse('2026-10-08'));

        $this->assertFalse($today['ask']);
        $this->assertFalse($today['has_employee']);
    }
}
