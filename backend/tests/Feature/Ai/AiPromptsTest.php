<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Modules\Ai\Contracts\AiPromptTemplate;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Models\AiRequest;
use App\Modules\Ai\Support\AiPromptRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\AiFixtures;
use Tests\TestCase;

/**
 * The versioned prompts as pure units on the synthetic fixtures (tests/Fixtures/ai): stable system prefix, variable
 * data only in the user message, no contacts/names in the screening prompt, parse + compare, and the ai:experiment
 * harness.
 */
final class AiPromptsTest extends TestCase
{
    use AiFixtures;
    use RefreshDatabase;

    private const array PURPOSES = ['script_evaluation', 'mail_classification', 'candidate_screening'];

    public function test_every_purpose_has_six_fixtures_and_a_registered_template(): void
    {
        foreach (self::PURPOSES as $purpose) {
            $this->assertCount(6, $this->cases($purpose), $purpose);
            $this->assertInstanceOf(AiPromptTemplate::class, $this->app->make(AiPromptRegistry::class)->find(AiPurpose::from($purpose)), $purpose);
        }
    }

    public function test_system_part_is_stable_and_holds_no_call_specific_data(): void
    {
        foreach (self::PURPOSES as $purpose) {
            $template = $this->template($purpose);
            $systems = [];
            foreach ($this->cases($purpose) as $case) {
                $prompt = $template->fromFixture($case['input']);
                $this->assertSame(['system', 'user'], array_column($prompt->messages(), 'role'));
                $this->assertDoesNotMatchRegularExpression('/\b20\d\d-\d\d-\d\d\b|\d{1,2}:\d\d:\d\d/', $prompt->system, $purpose.': no dates/times in the system part');
                // Twice the same input → byte-identical prompt (cacheable, deterministic).
                $this->assertSame($prompt->system, $template->fromFixture($case['input'])->system);
                $systems[] = $prompt->system;
            }
            if ($purpose !== 'script_evaluation') {
                // Instructions only: the same system text for every letter / candidate.
                $this->assertCount(1, array_unique($systems), $purpose);
            }
        }
        $script = $this->template('script_evaluation')->fromFixture($this->cases('script_evaluation')[0]['input']);
        $this->assertStringNotContainsString('Добрий день, мене звати Олена, я з компанії Тест. Кандидат', $script->system, 'Transcript is not in the system part.');
        $this->assertStringContainsString('Кандидат: Так, слухаю', $script->user);
    }

    public function test_screening_prompt_sends_no_name_or_contacts(): void
    {
        $case = $this->cases('candidate_screening')[0];
        $prompt = $this->template('candidate_screening')->fromFixture($case['input']);

        $this->assertStringNotContainsString('test.testovych@example.test', $prompt->user);
        $this->assertStringNotContainsString('Тест Тестович', $prompt->user);
        $this->assertStringContainsString('[email]', $prompt->user);
        $this->assertStringContainsString('Laravel', $prompt->user);
    }

    public function test_parse_and_compare_accept_answers_that_match_the_expected_output(): void
    {
        foreach (self::PURPOSES as $purpose) {
            $template = $this->template($purpose);
            foreach ($this->cases($purpose) as $case) {
                $parsed = $template->parse((string) json_encode($this->idealAnswer($purpose, $case), JSON_UNESCAPED_UNICODE));
                $checks = $template->compare($parsed, $case['expected']);
                $this->assertNotContains(false, $checks, $purpose.'/'.$case['name'].': '.json_encode($checks));
            }
        }
    }

    public function test_experiment_command_runs_fixtures_against_the_broker(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        $this->enableAi();
        $this->fakeBroker([[self::pendingAnswer(), self::doneAnswer(['kind' => 'newsletter', 'parser' => null, 'conf' => 0.95,
            'cand' => ['name' => null, 'phone' => null, 'email' => null, 'vacancy' => null]])]]);

        $code = Artisan::call('ai:experiment', [
            'purpose' => 'mail_classification',
            'capability' => 'chat:fast',
            'fixture' => 'tests/Fixtures/ai/mail_classification.json',
            '--case' => 'newsletter_digest',
        ]);
        $out = json_decode(Artisan::output(), true);

        $this->assertSame(0, $code);
        $this->assertSame(['chat:fast'], $this->brokerCapabilities);
        $this->assertSame(1, $out['summary']['cases']);
        $this->assertSame(1, $out['summary']['passed']);
        $this->assertTrue($out['cases'][0]['valid']);
        $this->assertSame(1024, $out['cases'][0]['tokens_cached']);
        $this->assertSame(0, AiRequest::query()->count(), 'The harness stores nothing.');
    }

    public function test_experiment_command_refuses_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->assertSame(1, Artisan::call('ai:experiment', ['purpose' => 'test', 'capability' => 'chat:sales', 'fixture' => 'x.json']));
    }

    /**
     * An answer a perfect model would give for the case (used to test parse + compare, not the model).
     *
     * @param  array{name: string, input: array<string, mixed>, expected: array<string, mixed>}  $case
     * @return array<string, mixed>
     */
    private function idealAnswer(string $purpose, array $case): array
    {
        $e = $case['expected'];

        return match ($purpose) {
            'script_evaluation' => [
                'steps' => array_map(static fn (array $s): array => [
                    'id' => $s['id'], 'done' => in_array($s['id'], $e['done_steps'], true), 'quote' => null, 'note' => 'ok',
                ], $case['input']['script']['steps']),
                'handled' => $e['objections_handled'] ?? [],
                'next' => $e['next_step_fixed'],
                'next_quote' => null,
                'tips' => [],
            ],
            'mail_classification' => [
                'kind' => $e['kind'],
                'parser' => $e['parser'] ?? ($e['kind'] === 'job_board' ? 'generic' : null),
                'conf' => ($e['auto_apply'] ?? false) ? 0.95 : 0.75,
                'cand' => ['name' => $e['extracted']['full_name'] ?? null, 'phone' => null, 'email' => null,
                    'vacancy' => $e['extracted']['vacancy_title'] ?? null],
            ],
            default => [
                'score' => match ($e['verdict'] ?? null) {
                    'fit' => 85,
                    'maybe' => 55,
                    'no' => 20,
                    default => intdiv(($e['score_min'] ?? 0) + ($e['score_max'] ?? 100), 2),
                },
                'summary' => 'Синтетичне саммарі', 'pros' => [], 'cons' => [], 'ask' => [],
            ],
        };
    }

    private function template(string $purpose): AiPromptTemplate
    {
        $template = $this->app->make(AiPromptRegistry::class)->find(AiPurpose::from($purpose));
        $this->assertNotNull($template);

        return $template;
    }

    /** @return list<array{name: string, input: array<string, mixed>, expected: array<string, mixed>}> */
    private function cases(string $purpose): array
    {
        $data = json_decode((string) file_get_contents(base_path("tests/Fixtures/ai/{$purpose}.json")), true, 64, JSON_THROW_ON_ERROR);

        return $data['cases'];
    }
}
