<?php

declare(strict_types=1);

namespace Tests\Feature\Knowledge;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/** Knowledge base: authz, sanitization (XSS), audience, search, versions, votes. Synthetic data only. */
final class KnowledgeApiTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    public function test_writing_is_admin_only_and_drafts_are_hidden(): void
    {
        $this->getJson('/api/knowledge/articles')->assertUnauthorized();
        $admin = $this->login(UserRole::Admin);
        $reader = $this->login(UserRole::Viewer);
        $this->actingAs($reader)->postJson('/api/knowledge/articles', ['title' => 'x', 'body_md' => 'y'])->assertForbidden();
        $this->actingAs($reader)->postJson('/api/knowledge/categories', ['name' => 'x'])->assertForbidden();

        $category = $this->actingAs($admin)->postJson('/api/knowledge/categories', ['name' => 'Office rules', 'emoji' => '🏢'])->assertCreated()->json('data.id');
        $draft = $this->actingAs($admin)->postJson('/api/knowledge/articles', ['title' => 'Draft rules', 'body_md' => 'soon', 'category_id' => $category])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->actingAs($reader)->getJson('/api/knowledge/articles')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($reader)->getJson("/api/knowledge/articles/$draft")->assertNotFound();
        $this->actingAs($reader)->postJson("/api/knowledge/articles/$draft/vote", ['helpful' => true])->assertNotFound();
        $this->actingAs($reader)->getJson("/api/knowledge/articles/$draft/versions")->assertForbidden();
        $this->actingAs($admin)->getJson('/api/knowledge/articles')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_markdown_is_sanitized_with_the_documents_renderer(): void
    {
        $admin = $this->login(UserRole::Admin);
        $body = "# Hello\n\n<script>alert(1)</script>\n\n[x](javascript:alert(2)) <img src=x onerror=alert(3)>\n\n**bold**";
        $html = (string) $this->actingAs($admin)->postJson('/api/knowledge/articles', ['title' => 'XSS', 'body_md' => $body, 'status' => 'published'])
            ->assertCreated()->json('data.html');

        $this->assertStringContainsString('<h1>Hello</h1>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_audience_by_branch_and_role(): void
    {
        $admin = $this->login(UserRole::Admin);
        $kyiv = Branch::query()->create(['name' => 'Kyiv test', 'status' => 'active']);
        $lviv = Branch::query()->create(['name' => 'Lviv test', 'status' => 'active']);
        $kyivUser = $this->login(UserRole::Viewer);
        $this->employee(['branch_id' => $kyiv->id], $kyivUser);
        $recruiter = $this->login(UserRole::Recruiter);
        $recruiter->branches()->sync([$lviv->id]);

        $post = fn (string $title, array $audience) => $this->actingAs($admin)->postJson('/api/knowledge/articles', [
            'title' => $title, 'body_md' => 'text', 'status' => 'published', 'audience' => $audience,
        ])->assertCreated()->json('data.id');
        $post('For all', ['type' => 'all']);
        $kyivOnly = $post('Kyiv office', ['type' => 'branches', 'ids' => [$kyiv->id]]);
        $post('Lviv office', ['type' => 'branches', 'ids' => [$lviv->id]]);
        $post('Recruiter guide', ['type' => 'roles', 'roles' => ['recruiter']]);

        $titles = fn ($user): array => array_column((array) $this->actingAs($user)->getJson('/api/knowledge/articles')->assertOk()->json('data'), 'title');
        $this->assertEqualsCanonicalizing(['For all', 'Kyiv office'], $titles($kyivUser));
        $this->assertEqualsCanonicalizing(['For all', 'Lviv office', 'Recruiter guide'], $titles($recruiter));
        $this->assertCount(4, $titles($admin));
        $this->actingAs($recruiter)->getJson("/api/knowledge/articles/$kyivOnly")->assertNotFound();
        $this->actingAs($admin)->postJson('/api/knowledge/articles', ['title' => 'x', 'body_md' => 'y', 'audience' => ['type' => 'roles', 'roles' => ['boss']]])
            ->assertUnprocessable();
    }

    public function test_search_versions_and_votes(): void
    {
        $admin = $this->login(UserRole::Admin);
        $reader = $this->login(UserRole::Viewer);
        $id = $this->actingAs($admin)->postJson('/api/knowledge/articles', [
            'title' => 'Vacation Policy', 'body_md' => 'Request 14 days ahead', 'tags' => ['Leave', ' leave ', 'HR'], 'status' => 'published',
        ])->assertCreated()->assertJsonPath('data.tags', ['hr', 'leave'])->json('data.id');
        $this->actingAs($admin)->postJson('/api/knowledge/articles', ['title' => '100% remote_work', 'body_md' => 'Rules', 'status' => 'published'])->assertCreated();

        $search = fn (string $q): array => array_column((array) $this->actingAs($reader)->getJson('/api/knowledge/articles?q='.urlencode($q))->assertOk()->json('data'), 'title');
        $this->assertSame(['Vacation Policy'], $search('vacation'));
        $this->assertSame(['Vacation Policy'], $search('14 DAYS'));
        $this->assertSame(['100% remote_work'], $search('0% r'), 'wildcards are literal');
        $this->assertSame([], $search('_'.'x'));
        $this->actingAs($reader)->getJson('/api/knowledge/articles?tag=leave')->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($admin)->patchJson("/api/knowledge/articles/$id", ['body_md' => 'Request 21 days ahead'])->assertOk()->assertJsonPath('data.version', 2);
        $this->actingAs($admin)->patchJson("/api/knowledge/articles/$id", ['tags' => ['hr']])->assertOk()->assertJsonPath('data.version', 2);
        $this->actingAs($admin)->getJson("/api/knowledge/articles/$id/versions")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.version', 1)->assertJsonPath('data.0.body_md', 'Request 14 days ahead');

        $this->actingAs($reader)->postJson("/api/knowledge/articles/$id/vote", ['helpful' => false])->assertOk()->assertJsonPath('data.my_vote', false);
        $this->actingAs($reader)->postJson("/api/knowledge/articles/$id/vote", ['helpful' => true])->assertOk()
            ->assertJsonPath('data.my_vote', true)->assertJsonPath('data.votes', ['helpful' => 1, 'not_helpful' => 0])
            ->assertJsonPath('data.body_md', null);
    }
}
