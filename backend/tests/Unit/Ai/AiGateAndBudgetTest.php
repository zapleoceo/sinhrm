<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Modules\Ai\Contracts\AiProvider;
use App\Modules\Ai\Contracts\AiRequestRepository;
use App\Modules\Ai\DTO\AiSettings;
use App\Modules\Ai\DTO\AiUsage;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Exceptions\AiException;
use App\Modules\Ai\Services\AiBrokerProvider;
use App\Modules\Ai\Services\AiBudget;
use App\Modules\Ai\Services\AiGate;
use App\Modules\Ai\Support\AiSettingsReader;
use App\Modules\Integrations\Contracts\AiPolicy;
use Illuminate\Support\Carbon;
use ReflectionClass;
use Tests\TestCase;

/** AiGate and AiBudget, extracted from AiService: the same refusal codes in the same order, the same daily caps. */
final class AiGateAndBudgetTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_gate_refuses_in_order_switch_configuration_purpose(): void
    {
        $settings = $this->settings(projectKey: 'k', purposes: ['candidate_screening' => false, 'script_evaluation' => true]);

        self::assertSame('ai_disabled', $this->gate(false, AiBrokerProvider::KEY, $settings)->unavailableReason(AiPurpose::Test));
        self::assertSame('ai_not_configured', $this->gate(true, AiBrokerProvider::KEY, $this->settings(projectKey: null))->unavailableReason(AiPurpose::Test));
        self::assertNull($this->gate(true, 'openrouter', $this->settings(projectKey: null))->unavailableReason(AiPurpose::Test));
        self::assertSame('ai_purpose_disabled', $this->gate(true, AiBrokerProvider::KEY, $settings)->unavailableReason(AiPurpose::CandidateScreening));
        self::assertNull($this->gate(true, AiBrokerProvider::KEY, $settings)->unavailableReason(AiPurpose::ScriptEvaluation));
    }

    public function test_gate_assert_throws_the_matching_exception(): void
    {
        try {
            $this->gate(true, AiBrokerProvider::KEY, $this->settings(projectKey: null))->assertAvailable(AiPurpose::Test);
            self::fail('expected AiException');
        } catch (AiException $e) {
            self::assertSame('ai_not_configured', $e->errorCode);
        }
    }

    public function test_budget_counts_since_utc_midnight_and_stops_at_either_cap(): void
    {
        Carbon::setTestNow('2026-10-08 15:30:00');
        $settings = $this->reader($this->settings(projectKey: 'k', maxRequests: 10, maxCost: 1.0));

        $since = null;
        $requests = $this->createMock(AiRequestRepository::class);
        $requests->method('usageSince')->willReturnCallback(static function (Carbon $s) use (&$since): AiUsage {
            $since = $s;

            return new AiUsage(9, 0.5);
        });
        $budget = new AiBudget($settings, $requests);
        $budget->assertWithin();
        self::assertSame(9, $budget->usageToday()->requests);
        self::assertInstanceOf(Carbon::class, $since);
        self::assertSame('2026-10-08 00:00:00 UTC', $since->format('Y-m-d H:i:s T'));

        foreach ([new AiUsage(10, 0.0), new AiUsage(0, 1.0)] as $usage) {
            $full = $this->createStub(AiRequestRepository::class);
            $full->method('usageSince')->willReturn($usage);
            try {
                (new AiBudget($settings, $full))->assertWithin();
                self::fail('expected ai_budget_exceeded');
            } catch (AiException $e) {
                self::assertSame('ai_budget_exceeded', $e->errorCode);
                self::assertSame(429, $e->status);
            }
        }
    }

    private function gate(bool $enabled, string $providerKey, AiSettings $settings): AiGate
    {
        $policy = $this->createStub(AiPolicy::class);
        $policy->method('enabled')->willReturn($enabled);
        $provider = $this->createStub(AiProvider::class);
        $provider->method('key')->willReturn($providerKey);

        return new AiGate($policy, $provider, $this->reader($settings));
    }

    /** A reader with already loaded settings (no integration config lookup). */
    private function reader(AiSettings $settings): AiSettingsReader
    {
        $class = new ReflectionClass(AiSettingsReader::class);
        $reader = $class->newInstanceWithoutConstructor();
        $class->getProperty('cached')->setValue($reader, $settings);

        return $reader;
    }

    /** @param  array<string, bool>  $purposes */
    private function settings(?string $projectKey, array $purposes = [], int $maxRequests = 100, float $maxCost = 5.0): AiSettings
    {
        return new AiSettings('https://broker.test', $projectKey, true, 'chat:fast', [], null, $maxRequests, $maxCost, $purposes, false);
    }
}
