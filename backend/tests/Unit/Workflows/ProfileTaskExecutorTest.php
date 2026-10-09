<?php

declare(strict_types=1);

namespace Tests\Unit\Workflows;

use App\Modules\Workflows\Enums\StepAction;
use App\Modules\Workflows\Executors\AssignBuddyExecutor;
use App\Modules\Workflows\Executors\CreateTaskExecutor;
use App\Modules\Workflows\Executors\ProfileTaskExecutor;
use App\Modules\Workflows\Executors\RequestFormExecutor;
use Tests\TestCase;

/** The shared «title + profile link» task step keeps each action's config rules (the step editor validates by them). */
final class ProfileTaskExecutorTest extends TestCase
{
    public function test_title_only_steps_and_request_form_rules(): void
    {
        $title = ['title' => ['nullable', 'string', 'max:255']];
        $create = $this->app->make(CreateTaskExecutor::class);
        $buddy = $this->app->make(AssignBuddyExecutor::class);
        $form = $this->app->make(RequestFormExecutor::class);

        foreach ([$create, $buddy, $form] as $executor) {
            self::assertInstanceOf(ProfileTaskExecutor::class, $executor);
        }
        self::assertSame(StepAction::CreateTask, $create->action());
        self::assertSame(StepAction::AssignBuddy, $buddy->action());
        self::assertSame($title, $create->configRules());
        self::assertSame($title, $buddy->configRules());
        self::assertSame($title + ['url' => ['nullable', 'url:https', 'max:500']], $form->configRules());
        self::assertSame(['title', 'url'], array_keys($form->configRules()));
    }
}
