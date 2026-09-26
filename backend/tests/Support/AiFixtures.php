<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Integrations\Contracts\HostResolver;
use App\Modules\Integrations\Contracts\SecretVault;
use App\Modules\Integrations\Enums\IntegrationStatus;
use App\Modules\Integrations\Models\Integration;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * AI Broker test double: switches AI on with a synthetic project key and answers /v1/jobs submits and polls from a
 * script. Only for tests; the key is an obviously fake "synthetic-" value (.gitleaks.toml).
 */
trait AiFixtures
{
    protected const string PROJECT_KEY = 'synthetic-aib-project-key-7788';

    protected const string BROKER = 'https://aib.zapleo.com';

    /** @var list<array<string, mixed>> JSON bodies of every POST /v1/jobs */
    protected array $brokerSubmits = [];

    /** @var list<string> capability query of every POST /v1/jobs */
    protected array $brokerCapabilities = [];

    protected int $brokerPolls = 0;

    /**
     * Global switch on, AI Broker integration in "demo" with the synthetic key, extra ai_broker settings merged.
     *
     * @param  array<string, string|null>  $settings
     */
    protected function enableAi(array $settings = []): void
    {
        $this->app->instance(HostResolver::class, new FakeHostResolver);
        Integration::query()->updateOrCreate(['key' => 'ai_policy'], ['settings' => ['enabled' => true]]);
        $row = Integration::query()->firstOrCreate(['key' => 'ai_broker']);
        $row->status = IntegrationStatus::Demo;
        $row->settings = array_merge($row->settings, $settings);
        $row->save();
        $this->app->make(SecretVault::class)->put('ai_broker', 'project_key', self::PROJECT_KEY);
    }

    /**
     * Fakes the broker. $jobs[i] = the poll answers of the i-th submitted job (the last answer repeats; jobs beyond the
     * list reuse the last list). Other hosts fall through to later fakes (e.g. Gmail) or fail as stray requests.
     *
     * @param  list<list<array<string, mixed>>>  $jobs
     */
    protected function fakeBroker(array $jobs, int $submitStatus = 202): void
    {
        $submitted = 0;
        $served = [];
        Http::fake(function (Request $request) use ($jobs, $submitStatus, &$submitted, &$served) {
            $url = $request->url();
            if (! str_starts_with($url, self::BROKER.'/v1/jobs')) {
                return null;
            }
            $path = (string) parse_url($url, PHP_URL_PATH);
            if ($request->method() === 'POST' && $path === '/v1/jobs') {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $this->brokerCapabilities[] = (string) ($query['capability'] ?? '');
                $this->brokerSubmits[] = $request->data();
                $submitted++;

                return Http::response(['job_id' => 1000 + $submitted, 'status' => 'pending', 'poll_url' => '/v1/jobs/'.(1000 + $submitted), 'poll_after_s' => 2], $submitStatus);
            }
            $this->brokerPolls++;
            $index = (int) basename($path) - 1001;
            $answers = $jobs[min($index, count($jobs) - 1)] ?? [self::pendingAnswer()];
            $served[$index] = ($served[$index] ?? 0) + 1;

            return Http::response(['job_id' => 1001 + $index] + $answers[min($served[$index] - 1, count($answers) - 1)]);
        });
    }

    /**
     * @param  array<string, mixed>|string  $json  the model's answer (array → JSON)
     * @return array<string, mixed>
     */
    protected static function doneAnswer(array|string $json, float $cost = 0.004): array
    {
        return [
            'status' => 'done',
            'text' => is_string($json) ? $json : json_encode($json, JSON_UNESCAPED_UNICODE),
            'provider' => 'synthetic',
            'model' => 'synthetic-model',
            'tokens_in' => 1200,
            'tokens_out' => 300,
            'cache_read_tokens' => 1024,
            'cost_usd' => $cost,
            'finish_reason' => 'stop',
        ];
    }

    /** @return array<string, mixed> */
    protected static function pendingAnswer(): array
    {
        return ['status' => 'pending', 'poll_after_s' => 2];
    }
}
