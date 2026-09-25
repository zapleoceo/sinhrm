<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Scripts\DTO\ScriptContent;
use App\Modules\Scripts\Enums\ScriptChannel;
use App\Modules\Scripts\Models\Script;
use App\Modules\Scripts\Services\ScriptService;

/**
 * Builders for Scripts tests. SYNTHETIC scripts only (public repository): the real company script is entered through
 * the UI into the database and never committed.
 */
trait ScriptFixtures
{
    /** @return array<string, mixed> a generic 4-step script with one objection, two templates and two follow-ups */
    protected function syntheticContent(): array
    {
        return [
            'steps' => [
                ['id' => 's1', 'title' => 'Greeting', 'goal' => 'Introduce yourself', 'sample' => 'Hello, this is the recruiter.', 'required' => true, 'weight' => 20, 'keywords' => ['hello', 'добрий день']],
                ['id' => 's2', 'title' => 'Interest', 'goal' => 'Ask about experience', 'sample' => 'Tell me about your experience.', 'required' => false, 'weight' => 20, 'keywords' => ['experience']],
                ['id' => 's3', 'title' => 'Offer', 'goal' => 'Describe the vacancy', 'sample' => 'The position offers training.', 'required' => true, 'weight' => 30, 'keywords' => ['position', 'vacancy']],
                ['id' => 's4', 'title' => 'Next step', 'goal' => 'Book the interview', 'sample' => 'Let us book the interview.', 'required' => true, 'weight' => 30, 'keywords' => ['interview']],
            ],
            'objections' => [
                ['id' => 'o1', 'trigger' => 'too long', 'answer' => 'It takes less time than you think.'],
            ],
            'templates' => [
                ['id' => 't1', 'key' => 'first', 'title' => 'First message', 'text' => "Hi {Ім'я}! I am {Рекрутер}, about {Вакансія}. Link: {Посилання на співбесіду}"],
                ['id' => 't2', 'key' => 'reminder', 'title' => 'Reminder', 'text' => "{Ім'я}, a gentle reminder."],
            ],
            'followups' => [
                ['id' => 'f1', 'condition' => 'no_reply', 'delay_days' => 1, 'template_key' => 'reminder'],
                ['id' => 'f2', 'condition' => 'gone_silent', 'delay_days' => 3, 'template_key' => null],
            ],
            'next_step_patterns' => ['positive' => ['tomorrow', 'booked'], 'negative' => ['think about it']],
        ];
    }

    /**
     * A script with version 1 published and active.
     *
     * @param  array<string, mixed>|null  $content
     */
    protected function publishedScript(ScriptChannel $channel = ScriptChannel::Call, ?array $content = null, string $name = 'Synthetic script'): Script
    {
        $service = $this->app->make(ScriptService::class);
        $author = User::factory()->withRole(UserRole::Admin)->create();
        $script = $service->create($author, $name, $channel, ScriptContent::fromArray($content ?? $this->syntheticContent()));
        $service->publish($author, $script);

        return $script->refresh();
    }

    /** A synthetic call transcript that passes greeting, offer and interview and fixes the next step. */
    protected function goodTranscript(): string
    {
        return "Hello, my name is Alex. The position is a junior role.\nCould we book an interview? Great, see you tomorrow at 10.";
    }
}
