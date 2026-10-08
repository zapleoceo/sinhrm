<?php

declare(strict_types=1);

namespace Tests\Unit\Pulse;

use App\Models\User;
use App\Modules\People\Contracts\PeopleAccess;
use App\Modules\Pulse\Contracts\SurveyRepository;
use App\Modules\Pulse\Exceptions\PulseException;
use App\Modules\Pulse\Services\ResponseService;
use Mockery\MockInterface;
use Tests\Support\FakePeopleAccess;
use Tests\TestCase;

/** The survey form finds the respondent through People's PeopleAccess contract, not PeopleScope (no DB). */
final class ResponsePeopleAccessTest extends TestCase
{
    public function test_a_user_without_an_employee_card_gets_no_employee_before_any_wave_lookup(): void
    {
        $this->app->instance(PeopleAccess::class, new FakePeopleAccess);
        /** @var SurveyRepository&MockInterface $surveys */
        $surveys = $this->mock(SurveyRepository::class);
        $surveys->expects('findWave')->never();
        $user = new User;
        $user->id = 3;

        $this->expectException(PulseException::class);

        $this->app->make(ResponseService::class)->form($user, 1);
    }
}
