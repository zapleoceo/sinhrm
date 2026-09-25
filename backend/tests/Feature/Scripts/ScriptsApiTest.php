<?php

declare(strict_types=1);

namespace Tests\Feature\Scripts;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Scripts\Models\ScriptVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Support\RecruitingFixtures;
use Tests\Support\ScriptFixtures;
use Tests\TestCase;

final class ScriptsApiTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase, ScriptFixtures;

    public function test_guests_get_401(): void
    {
        $this->getJson('/api/scripts')->assertUnauthorized();
        $this->postJson('/api/scripts', ['name' => 'X', 'channel' => 'call'])->assertUnauthorized();
    }

    public function test_recruiter_and_viewer_read_but_cannot_change(): void
    {
        $script = $this->publishedScript();
        foreach ([UserRole::Recruiter, UserRole::Viewer] as $role) {
            $user = $this->userWith($role);
            $this->actingAs($user)->getJson('/api/scripts')->assertOk()->assertJsonPath('data.0.id', $script->id);
            $this->actingAs($user)->getJson("/api/scripts/{$script->id}")->assertOk()
                ->assertJsonPath('data.active_version.version', 1)
                ->assertJsonPath('data.active_version.content.steps.0.id', 's1');
            $this->actingAs($user)->getJson("/api/scripts/{$script->id}/versions")->assertOk()->assertJsonCount(1, 'data');
            $this->actingAs($user)->postJson("/api/scripts/{$script->id}/test", ['text' => 'hello'])->assertOk();

            $this->actingAs($user)->postJson('/api/scripts', ['name' => 'X', 'channel' => 'call'])->assertForbidden();
            $this->actingAs($user)->patchJson("/api/scripts/{$script->id}", ['name' => 'Y'])->assertForbidden();
            $this->actingAs($user)->putJson("/api/scripts/{$script->id}/draft", $this->syntheticContent())->assertForbidden();
            $this->actingAs($user)->postJson("/api/scripts/{$script->id}/publish")->assertForbidden();
            $this->actingAs($user)->postJson("/api/scripts/{$script->id}/activate/1")->assertForbidden();
        }
        $this->actingAs($this->userWith(UserRole::Viewer))->getJson('/api/scripts/999999')->assertNotFound();
    }

    public function test_admin_creates_drafts_publishes_and_rolls_back(): void
    {
        $admin = $this->userWith(UserRole::Admin);

        $id = $this->actingAs($admin)->postJson('/api/scripts', ['name' => 'Call', 'channel' => 'call'] + $this->syntheticContent())
            ->assertCreated()
            ->assertJsonPath('data.active_version', null)
            ->assertJsonPath('data.draft.version', 1)
            ->assertJsonPath('data.draft.is_draft', true)
            ->assertJsonPath('data.draft.content.templates.0.key', 'first')
            ->json('data.id');

        $this->actingAs($admin)->postJson("/api/scripts/$id/publish")->assertOk()
            ->assertJsonPath('data.active_version.version', 1)
            ->assertJsonPath('data.draft', null);
        $this->actingAs($admin)->postJson("/api/scripts/$id/publish")->assertUnprocessable()->assertJsonPath('code', 'no_draft');

        // Editing after publish → a new draft (v2); v1 stays as it was.
        $changed = $this->syntheticContent();
        $changed['steps'][0]['title'] = 'Greeting v2';
        $this->actingAs($admin)->putJson("/api/scripts/$id/draft", $changed)->assertOk()
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.is_draft', true);
        $changed['steps'][0]['title'] = 'Greeting v2b';
        $this->actingAs($admin)->putJson("/api/scripts/$id/draft", $changed)->assertOk()->assertJsonPath('data.version', 2);
        $this->assertSame(2, ScriptVersion::query()->where('script_id', $id)->count());

        $this->actingAs($admin)->postJson("/api/scripts/$id/publish")->assertOk()
            ->assertJsonPath('data.active_version.version', 2)
            ->assertJsonPath('data.active_version.content.steps.0.title', 'Greeting v2b');

        $this->actingAs($admin)->postJson("/api/scripts/$id/activate/1")->assertOk()
            ->assertJsonPath('data.active_version.version', 1)
            ->assertJsonPath('data.active_version.content.steps.0.title', 'Greeting');
        $this->actingAs($admin)->postJson("/api/scripts/$id/activate/7")->assertUnprocessable()->assertJsonPath('code', 'version_not_published');

        $this->actingAs($admin)->putJson("/api/scripts/$id/draft", $changed)->assertOk()->assertJsonPath('data.version', 3);
        $this->actingAs($admin)->postJson("/api/scripts/$id/activate/3")->assertUnprocessable();

        $versions = $this->actingAs($admin)->getJson("/api/scripts/$id/versions")->assertOk()
            ->assertJsonPath('meta.active_version_id', ScriptVersion::query()->where('script_id', $id)->where('version', 1)->value('id'))
            ->json('data');
        $this->assertSame([3, 2, 1], array_column($versions, 'version'));
        $this->assertSame([true, false, false], array_column($versions, 'is_draft'));
    }

    public function test_published_versions_are_immutable(): void
    {
        $script = $this->publishedScript();
        $version = ScriptVersion::query()->where('script_id', $script->id)->firstOrFail();

        $this->expectException(LogicException::class);
        $version->update(['steps' => []]);
    }

    public function test_content_validation(): void
    {
        $admin = $this->userWith(UserRole::Admin);
        $script = $this->publishedScript();
        $url = "/api/scripts/{$script->id}/draft";

        $bad = $this->syntheticContent();
        $bad['templates'][0]['text'] = 'Hi {Імя}';
        $this->actingAs($admin)->putJson($url, $bad)->assertUnprocessable()->assertJsonValidationErrors('templates.0.text');

        $bad = $this->syntheticContent();
        $bad['followups'][0]['template_key'] = 'missing';
        $this->actingAs($admin)->putJson($url, $bad)->assertUnprocessable()->assertJsonValidationErrors('followups.0.template_key');

        $bad = $this->syntheticContent();
        $bad['next_step_patterns']['positive'] = ['(unclosed'];
        $this->actingAs($admin)->putJson($url, $bad)->assertUnprocessable()->assertJsonValidationErrors('next_step_patterns.positive.0');

        $bad = $this->syntheticContent();
        $bad['steps'][1]['id'] = 's1';
        $bad['steps'][2]['weight'] = '101';
        $bad['followups'][1]['condition'] = 'never';
        $this->actingAs($admin)->putJson($url, $bad)->assertUnprocessable()
            ->assertJsonValidationErrors(['steps.1.id', 'steps.2.weight', 'followups.1.condition']);

        $this->actingAs($admin)->postJson('/api/scripts', ['name' => '', 'channel' => 'sms'])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'channel']);

        // Weight arrives as a string from a form: accepted and stored as int; missing patterns → defaults.
        $ok = $this->syntheticContent();
        $ok['steps'][0]['weight'] = '25';
        unset($ok['next_step_patterns']);
        $this->actingAs($admin)->putJson($url, $ok)->assertOk()
            ->assertJsonPath('data.content.steps.0.weight', 25)
            ->assertJsonPath('data.content.next_step_patterns.positive.0', 'сьогодні');
    }

    public function test_archive_hides_script_and_blocks_editing(): void
    {
        $admin = $this->userWith(UserRole::Admin);
        $script = $this->publishedScript();

        $this->actingAs($admin)->patchJson("/api/scripts/{$script->id}", ['archived' => true, 'name' => 'Old'])->assertOk()
            ->assertJsonPath('data.archived', true)->assertJsonPath('data.name', 'Old');
        $this->actingAs($admin)->getJson('/api/scripts')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($admin)->getJson('/api/scripts?archived=1')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->putJson("/api/scripts/{$script->id}/draft", $this->syntheticContent())
            ->assertUnprocessable()->assertJsonPath('code', 'script_archived');
    }

    public function test_test_endpoint_previews_draft_or_active_version(): void
    {
        $admin = $this->userWith(UserRole::Admin);
        $script = $this->publishedScript();
        $url = "/api/scripts/{$script->id}/test";

        $this->actingAs($admin)->postJson($url, ['text' => $this->goodTranscript()])->assertOk()
            ->assertJsonPath('data.engine', 'rules')
            ->assertJsonPath('data.score', 80)
            ->assertJsonPath('data.steps.1.done', false)
            ->assertJsonPath('data.steps.2.quote', 'The position is a junior role.')
            ->assertJsonPath('data.next_step.fixed', true);

        // A draft where only "experience" counts: the draft is tested by default, the active version on request.
        $draft = $this->syntheticContent();
        $draft['steps'] = [['id' => 'x', 'title' => 'Experience', 'weight' => 100, 'required' => true, 'keywords' => ['experience']]];
        $this->actingAs($admin)->putJson("/api/scripts/{$script->id}/draft", $draft)->assertOk();
        $this->actingAs($admin)->postJson($url, ['text' => $this->goodTranscript()])->assertOk()->assertJsonPath('data.score', 0)
            ->assertJsonPath('data.recommendations.0.type', 'missed_step');
        $this->actingAs($admin)->postJson($url, ['text' => $this->goodTranscript(), 'version' => 'active'])->assertOk()->assertJsonPath('data.score', 80);
        $this->actingAs($admin)->postJson($url, ['text' => ''])->assertUnprocessable();
    }
}
