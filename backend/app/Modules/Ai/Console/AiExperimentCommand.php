<?php

declare(strict_types=1);

namespace App\Modules\Ai\Console;

use App\Modules\Ai\DTO\AiJobRef;
use App\Modules\Ai\DTO\AiResult;
use App\Modules\Ai\DTO\AiSettings;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Exceptions\AiException;
use App\Modules\Ai\Exceptions\InvalidAiOutput;
use App\Modules\Ai\Services\AiBrokerProvider;
use App\Modules\Ai\Support\AiPromptRegistry;
use App\Modules\Ai\Support\AiSettingsReader;
use App\Modules\Integrations\Definitions\AiBrokerDefinition;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use JsonException;

/**
 * Offline experiment harness (NOT for production): runs a purpose's versioned prompt on synthetic fixture cases against
 * the AI Broker with a chosen capability and prints JSON per case — latency, tokens (incl. cached), cost, model, parsed
 * output, validity and checks against the expected output. Uses the same build()/parse() as production.
 *
 *   php artisan ai:experiment mail_classification chat:fast tests/Fixtures/ai/mail_classification.json
 *
 * Key: env AIB_PROJECT_KEY (local shell only, never committed) or the vault; base URL: --base-url or the settings.
 * Nothing is stored (no ai_requests rows, no daily caps): the broker's own project cap still applies.
 */
final class AiExperimentCommand extends Command
{
    protected $signature = 'ai:experiment
        {purpose : script_evaluation | mail_classification | candidate_screening | test}
        {capability : chat:fast | chat:smart | chat:sales | structured}
        {fixture : path to a JSON file {cases: [{name, input, expected}]}}
        {--case= : run only the case with this name}
        {--timeout=120 : seconds to wait for one answer}
        {--base-url= : broker URL (default: the ai_broker setting)}';

    protected $description = 'Run an AI prompt on synthetic fixtures against the AI Broker and print JSON results (refuses in production).';

    public function handle(AiPromptRegistry $prompts, AiSettingsReader $reader): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('ai:experiment is disabled in production.');

            return self::FAILURE;
        }
        $purpose = AiPurpose::tryFrom((string) $this->argument('purpose'));
        $template = $purpose === null ? null : $prompts->find($purpose);
        $capability = (string) $this->argument('capability');
        if ($template === null || ! in_array($capability, AiBrokerDefinition::CAPABILITIES, true)) {
            $this->error('Unknown purpose or capability.');

            return self::INVALID;
        }
        $cases = $this->cases((string) $this->argument('fixture'));
        if ($cases === null) {
            $this->error('Fixture not found or not {cases: [...]} JSON.');

            return self::INVALID;
        }

        $settings = $reader->read();
        $key = getenv('AIB_PROJECT_KEY');
        $reader->override(new AiSettings(
            baseUrl: rtrim((string) ($this->option('base-url') ?: $settings->baseUrl), '/'),
            projectKey: is_string($key) && $key !== '' ? $key : $settings->projectKey,
            integrationOn: true,
            capability: $capability,
            capabilities: [],
            model: $settings->model,
            maxRequestsPerDay: $settings->maxRequestsPerDay,
            maxCostPerDay: $settings->maxCostPerDay,
            purposes: $settings->purposes,
            autoScreening: false,
        ));
        $provider = $this->laravel->make(AiBrokerProvider::class, ['settings' => $reader]);
        $timeout = max(5, (int) $this->option('timeout'));

        $results = [];
        foreach ($cases as $case) {
            if ($this->option('case') !== null && $case['name'] !== $this->option('case')) {
                continue;
            }
            $skip = $template->skipReason($case['input']);
            if ($skip !== null) {
                $results[] = ['name' => $case['name'], 'status' => 'skipped', 'error' => $skip, 'latency_ms' => 0, 'cost_usd' => 0.0,
                    'pass' => ($case['expected']['skip'] ?? null) === $skip];

                continue;
            }
            $prompt = $template->fromFixture($case['input'])->withCapability($capability);
            $started = hrtime(true);
            $row = ['name' => $case['name'], 'status' => 'error', 'error' => null];
            try {
                $result = $this->await($provider, $provider->submit($prompt), $timeout);
            } catch (AiException $e) {
                $result = AiResult::error($e->errorCode);
            }
            $row['latency_ms'] = (int) ((hrtime(true) - $started) / 1e6);
            $row += ['model' => $result->model, 'tokens_in' => $result->tokensIn, 'tokens_out' => $result->tokensOut,
                'tokens_cached' => $result->tokensCached, 'cost_usd' => $result->costUsd, 'finish_reason' => $result->finishReason];
            if ($result->isDone()) {
                $row['status'] = 'done';
                try {
                    $parsed = $template->parse((string) $result->text);
                    $checks = $template->compare($parsed, $case['expected']);
                    $row += ['valid' => true, 'parsed' => $parsed, 'checks' => $checks, 'pass' => ! in_array(false, $checks, true)];
                } catch (InvalidAiOutput $e) {
                    $row += ['valid' => false, 'invalid_reason' => $e->getMessage(), 'pass' => false];
                }
            } else {
                $row['error'] = $result->isPending() ? 'timeout' : $result->error;
                $row['pass'] = false;
            }
            $results[] = $row;
        }

        $this->line((string) json_encode([
            'purpose' => $purpose->value,
            'capability' => $capability,
            'prompt_version' => $template->version(),
            'summary' => [
                'cases' => count($results),
                'valid' => count(array_filter($results, static fn (array $r): bool => ($r['valid'] ?? false) === true)),
                'passed' => count(array_filter($results, static fn (array $r): bool => $r['pass'] === true)),
                'cost_usd' => round(array_sum(array_column($results, 'cost_usd')), 6),
                'avg_latency_ms' => $results === [] ? 0 : (int) (array_sum(array_column($results, 'latency_ms')) / count($results)),
            ],
            'cases' => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function await(AiBrokerProvider $provider, AiJobRef $job, int $timeout): AiResult
    {
        $deadline = microtime(true) + $timeout;
        $delay = max(1, $job->pollAfterSeconds);
        do {
            Sleep::for($delay)->seconds();
            $result = $provider->poll($job);
            $delay = min(10, max($delay + 1, $result->pollAfterSeconds));
        } while ($result->isPending() && microtime(true) < $deadline);

        return $result;
    }

    /** @return list<array{name: string, input: array<string, mixed>, expected: array<string, mixed>}>|null */
    private function cases(string $path): ?array
    {
        $full = is_file($path) ? $path : base_path($path);
        if (! is_file($full)) {
            return null;
        }
        try {
            $data = json_decode((string) file_get_contents($full), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($data) || ! is_array($data['cases'] ?? null)) {
            return null;
        }
        $cases = [];
        foreach ($data['cases'] as $i => $case) {
            if (is_array($case)) {
                /** @var array<string, mixed> $input */
                $input = is_array($case['input'] ?? null) ? $case['input'] : [];
                /** @var array<string, mixed> $expected */
                $expected = is_array($case['expected'] ?? null) ? $case['expected'] : [];
                $cases[] = ['name' => is_string($case['name'] ?? null) ? $case['name'] : 'case_'.$i, 'input' => $input, 'expected' => $expected];
            }
        }

        return $cases;
    }
}
