<?php

declare(strict_types=1);

namespace Tests\Unit\Recruiting;

use App\Models\User;
use App\Modules\Ai\Contracts\AiGateway;
use App\Modules\Ai\DTO\AiOutcome;
use App\Modules\Recruiting\Services\VacancyTextService;
use Mockery\MockInterface;
use Tests\TestCase;

/** «Створити з ШІ» goes through the AiGateway contract; the draft text comes from its outcome (no DB). */
final class RecruitingAiGatewayTest extends TestCase
{
    public function test_the_draft_is_the_text_of_the_gateway_outcome(): void
    {
        /** @var AiGateway&MockInterface $ai */
        $ai = $this->mock(AiGateway::class);
        $ai->expects('run')->andReturn(AiOutcome::done(31, ['text' => 'Draft text']));
        $actor = new User;
        $actor->id = 2;

        $result = $this->app->make(VacancyTextService::class)->generate($actor, [
            'section' => 'description', 'title' => 'Manager', 'category_id' => null, 'branch_id' => null,
            'employment_type' => null, 'experience_level' => null,
        ]);

        $this->assertSame(['status' => 'done', 'request_id' => 31, 'text' => 'Draft text', 'error' => null], $result);
    }
}
