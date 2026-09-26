<?php

declare(strict_types=1);

namespace Tests\Unit\Audit;

use App\Modules\Audit\Providers\AuditServiceProvider;
use App\Modules\Audit\Support\AuditPolicy;
use App\Modules\Pulse\Models\SurveyResponse;
use App\Modules\SafeSpeak\Models\SafeSpeakReport;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use PHPUnit\Framework\TestCase;

final class AuditPolicyTest extends TestCase
{
    /** Names that must never be allow-listed, whatever the model. */
    private const string SENSITIVE = '/salary|compensation|password|secret|token|value|phone|email|telegram|address|emergency|birth|personal|custom_fields|note|comment|body|content|path|file|utm|avatar|google_id|requirements|extra|settings|description|termination/i';

    private AuditPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new AuditPolicy;
    }

    public function test_fields_outside_the_allow_list_are_masked_and_noise_is_dropped(): void
    {
        $clean = $this->policy->sanitize('hiring_request', [
            'salary_min' => ['from' => '1000.00', 'to' => '2000.00'],
            'requirements' => ['from' => null, 'to' => 'free text'],
            'updated_at' => ['from' => '2026-01-01', 'to' => '2026-01-02'],
            'status' => ['from' => 'draft', 'to' => 'approved'],
            'brand_new_column' => ['from' => 'a', 'to' => 'b'],
        ]);

        $this->assertSame(['from' => '***', 'to' => '***'], $clean['salary_min']);
        $this->assertSame(['from' => null, 'to' => '***'], $clean['requirements']);
        $this->assertArrayNotHasKey('updated_at', $clean);
        $this->assertSame(['from' => 'draft', 'to' => 'approved'], $clean['status']);
        $this->assertSame(['from' => '***', 'to' => '***'], $clean['brand_new_column']);
        $this->assertSame(['from' => '***', 'to' => '***'], $this->policy->sanitize('nope', ['status' => ['from' => 'a', 'to' => 'b']])['status']);
    }

    /** Every tracked model, every fillable column: only allow-listed values survive; allow-lists hold no sensitive names. */
    public function test_every_tracked_model_stores_no_value_outside_its_allow_list(): void
    {
        foreach (AuditServiceProvider::TRACKED as $class => $type) {
            $this->assertArrayHasKey($type, AuditPolicy::SAFE_FIELDS, "no allow-list for {$type}");
            foreach (AuditPolicy::SAFE_FIELDS[$type] as $safe) {
                $this->assertDoesNotMatchRegularExpression(self::SENSITIVE, $safe, "{$type}.{$safe} looks sensitive");
            }

            $model = new $class;
            $fields = array_unique([...$model->getFillable(), ...array_keys($model->getCasts()), 'content_md', 'file_path', 'value', 'password']);
            $changes = [];
            foreach ($fields as $field) {
                $changes[$field] = ['from' => 'SECRET-OLD', 'to' => 'SECRET-NEW'];
            }
            foreach ($this->policy->sanitize($type, $changes) as $field => $pair) {
                if (! in_array($field, AuditPolicy::SAFE_FIELDS[$type], true)) {
                    $this->assertSame(['from' => '***', 'to' => '***'], $pair, "{$type}.{$field} leaked a value");
                }
            }
        }
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
