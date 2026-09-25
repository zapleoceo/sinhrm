<?php

declare(strict_types=1);

namespace Tests\Feature\Scripts;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Scripts\Enums\ScriptChannel;
use App\Modules\Scripts\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecruitingFixtures;
use Tests\Support\ScriptFixtures;
use Tests\TestCase;

final class TemplatesApiTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase, ScriptFixtures;

    public function test_templates_of_active_scripts_are_filled_for_the_candidate(): void
    {
        $branch = Branch::factory()->create();
        $vacancy = $this->vacancyIn($branch);
        $vacancy->update(['title' => 'Junior Tester']);
        $application = $this->applied($vacancy, ['full_name' => 'Olena Test']);
        $this->publishedScript(ScriptChannel::Chat);
        $archived = $this->publishedScript(ScriptChannel::Call, null, 'Archived');
        $archived->update(['archived' => true]);
        Script::query()->create(['name' => 'Draft only', 'channel' => 'chat']);
        $recruiter = $this->userWith(UserRole::Recruiter, [$branch]);
        $recruiter->update(['name' => 'Rita Recruiter']);
        $url = "/api/candidates/{$application->candidate_id}/templates";

        $this->actingAs($recruiter)->getJson($url)->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.key', 'first')
            ->assertJsonPath('data.0.channel', 'chat')
            ->assertJsonPath('data.0.text', 'Hi Olena! I am Rita Recruiter, about Junior Tester. Link: {Посилання на співбесіду}')
            ->assertJsonPath('data.0.missing', ['Посилання на співбесіду'])
            ->assertJsonPath('data.1.text', 'Olena, a gentle reminder.');

        $this->actingAs($this->userWith(UserRole::Recruiter, [Branch::factory()->create()]))->getJson($url)->assertForbidden();
    }

    public function test_without_an_active_application_the_vacancy_token_stays(): void
    {
        $branch = Branch::factory()->create();
        $application = $this->applied($this->vacancyIn($branch), ['full_name' => 'Ivan']);
        $application->update(['status' => 'rejected']);
        $this->publishedScript(ScriptChannel::Chat);

        $this->actingAs($this->userWith(UserRole::Admin))->getJson("/api/candidates/{$application->candidate_id}/templates")->assertOk()
            ->assertJsonPath('data.0.missing', ['Вакансія', 'Посилання на співбесіду']);
    }
}
