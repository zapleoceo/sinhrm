<?php

declare(strict_types=1);

namespace Tests\Unit\Audit;

use App\Modules\Audit\Providers\AuditServiceProvider;
use App\Modules\Audit\Support\AuditPolicy;
use App\Modules\Pulse\Models\SurveyResponse;
use App\Modules\SafeSpeak\Models\SafeSpeakReport;
use LogicException;
use PHPUnit\Framework\TestCase;

final class AuditPolicyTest extends TestCase
{
    private AuditPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new AuditPolicy;
    }

    public function test_sensitive_fields_are_masked_and_noise_is_dropped(): void
    {
        $clean = $this->policy->sanitize([
            'salary_min' => ['from' => '1000.00', 'to' => '2000.00'],
            'phone' => ['from' => null, 'to' => '+380501112233'],
            'value' => ['from' => 'old-secret', 'to' => 'new-secret'],
            'password' => ['from' => 'x', 'to' => 'y'],
            'termination_reason' => ['from' => null, 'to' => 'private story'],
            'updated_at' => ['from' => '2026-01-01', 'to' => '2026-01-02'],
            'status' => ['from' => 'draft', 'to' => 'approved'],
            'extra' => ['from' => null, 'to' => '{"a":1}'],
        ]);

        $this->assertSame(['from' => '***', 'to' => '***'], $clean['salary_min']);
        $this->assertSame(['from' => null, 'to' => '***'], $clean['phone']);
        $this->assertSame(['from' => '***', 'to' => '***'], $clean['value']);
        $this->assertSame(['from' => '***', 'to' => '***'], $clean['password']);
        $this->assertSame(['from' => null, 'to' => '***'], $clean['termination_reason']);
        $this->assertArrayNotHasKey('updated_at', $clean);
        $this->assertSame(['from' => 'draft', 'to' => 'approved'], $clean['status']);
        $this->assertSame(['from' => null, 'to' => ['a' => 1]], $clean['extra']);
    }

    public function test_anonymous_modules_are_refused(): void
    {
        foreach ([SafeSpeakReport::class, SurveyResponse::class] as $model) {
            try {
                $this->policy->assertTrackable($model);
                $this->fail("{$model} must be refused");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(LogicException::class);
        $this->policy->assertEntityType('safe_speak_report');
    }

    public function test_no_tracked_model_comes_from_an_anonymous_module(): void
    {
        foreach (array_keys(AuditServiceProvider::TRACKED) as $model) {
            $this->policy->assertTrackable($model);
            $this->assertStringNotContainsString('SafeSpeak', $model);
            $this->assertStringNotContainsString('Pulse', $model);
        }
        foreach (['survey_response', 'mood_checkin', 'pulse_x'] as $type) {
            try {
                $this->policy->assertEntityType($type);
                $this->fail("{$type} must be refused");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
