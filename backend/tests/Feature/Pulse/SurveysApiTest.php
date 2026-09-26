<?php

declare(strict_types=1);

namespace Tests\Feature\Pulse;

use App\Modules\Auth\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\PeopleFixtures;
use Tests\Support\PulseFixtures;
use Tests\TestCase;

/** The survey builder and wave scheduling (admins only), validation, question freeze after answers. */
final class SurveysApiTest extends TestCase
{
    use PeopleFixtures, PulseFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_builder_is_admin_only_and_validates_questions(): void
    {
        $admin = $this->login(UserRole::Admin);
        $this->actingAs($this->login())->getJson('/api/pulse/surveys')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/pulse/templates')->assertOk()->assertJsonPath('data.0.key', 'enps');

        $id = $this->actingAs($admin)->postJson('/api/pulse/surveys', ['title' => 'Q4 pulse', 'type' => 'engagement', 'questions' => $this->questions])
            ->assertCreated()->assertJsonPath('data.questions.2.options', ['Team', 'Tasks', 'Pay'])->json('data.id');
        $this->actingAs($admin)->postJson('/api/pulse/surveys', ['title' => 'Bad', 'type' => 'engagement', 'questions' => [
            ['id' => 'a', 'type' => 'single', 'text' => 'No options'],
            ['id' => 'a', 'type' => 'scale5', 'text' => 'Duplicate id'],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['questions.0.options', 'questions.0.id']);
        $this->actingAs($admin)->postJson('/api/pulse/surveys', ['title' => 'Bad', 'type' => 'custom', 'lifecycle_trigger' => 'exit', 'questions' => $this->questions])
            ->assertUnprocessable()->assertJsonValidationErrors(['lifecycle_trigger']);
        $this->actingAs($admin)->getJson("/api/pulse/surveys/{$id}")->assertOk()->assertJsonPath('data.waves_count', 0);
    }

    public function test_wave_rules_and_question_freeze(): void
    {
        $admin = $this->login(UserRole::Admin);
        $survey = $this->survey();
        $url = "/api/pulse/surveys/{$survey->id}/waves";

        $this->actingAs($admin)->postJson($url, ['starts_at' => '2026-10-05', 'ends_at' => '2026-10-10', 'schedule' => 'once', 'audience' => [], 'min_group_size' => 3])
            ->assertUnprocessable()->assertJsonValidationErrors(['min_group_size']);
        $this->actingAs($admin)->postJson($url, ['starts_at' => '2026-10-05', 'ends_at' => '2026-10-20', 'schedule' => 'weekly', 'audience' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['ends_at']);
        // Non-anonymous waves may use a smaller group.
        $this->actingAs($admin)->postJson($url, ['starts_at' => '2026-10-06', 'ends_at' => '2026-10-10', 'schedule' => 'once', 'audience' => [], 'anonymous' => false, 'min_group_size' => 1])
            ->assertCreated()->assertJsonPath('data.status', 'scheduled');
        $wave = $this->actingAs($admin)->postJson($url, ['starts_at' => '2026-10-05', 'ends_at' => '2026-10-11', 'schedule' => 'weekly', 'audience' => []])
            ->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonPath('data.anonymous', true)->assertJsonPath('data.min_group_size', 5);
        $this->assertArrayNotHasKey('salt', (array) $wave->json('data'));
        $this->actingAs($admin)->getJson($url)->assertOk()->assertJsonCount(2, 'data');

        [$person] = $this->people(1);
        $this->answer($survey->waves()->where('status', 'open')->firstOrFail(), $person, ['enps' => 9, 'q1' => 4]);
        $changed = $this->questions;
        $changed[1]['text'] = 'Changed';
        $this->actingAs($admin)->putJson("/api/pulse/surveys/{$survey->id}", ['title' => 'Renamed', 'type' => 'engagement', 'questions' => $changed])
            ->assertConflict()->assertJsonPath('code', 'has_responses');
        $this->actingAs($admin)->putJson("/api/pulse/surveys/{$survey->id}", ['title' => 'Renamed', 'type' => 'engagement', 'questions' => $this->questions])
            ->assertOk()->assertJsonPath('data.title', 'Renamed');
        $this->actingAs($admin)->deleteJson("/api/pulse/surveys/{$survey->id}")->assertConflict();
    }
}
