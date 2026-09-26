<?php

declare(strict_types=1);

namespace Tests\Feature\Knowledge;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Knowledge\Models\KbArticle;
use App\Modules\Knowledge\Models\KbArticleVersion;
use App\Modules\Knowledge\Models\KbVote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/** Per-endpoint coverage of /api/knowledge/*: happy path, 422 (incl. string params), 403 per role, 404, rules. Synthetic. */
final class KnowledgeEndpointsTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    /** @return iterable<string, array{UserRole}> */
    public static function nonAdminRoles(): iterable
    {
        yield 'recruiter' => [UserRole::Recruiter];
        yield 'viewer' => [UserRole::Viewer];
    }

    /** @param  array<string, mixed>  $attributes */
    private function article(User $admin, array $attributes = []): int
    {
        return (int) $this->actingAs($admin)->postJson('/api/knowledge/articles', $attributes + ['title' => 'Synthetic', 'body_md' => 'text', 'status' => 'published'])
            ->assertCreated()->json('data.id');
    }

    #[DataProvider('nonAdminRoles')]
    public function test_write_endpoints_are_forbidden_for_non_admin_roles(UserRole $role): void
    {
        $admin = $this->login(UserRole::Admin);
        $category = $this->actingAs($admin)->postJson('/api/knowledge/categories', ['name' => 'Rules'])->json('data.id');
        $id = $this->article($admin, ['title' => 'Original']);
        $user = $this->login($role);

        $this->actingAs($user)->postJson('/api/knowledge/categories', ['name' => 'X'])->assertForbidden();
        $this->actingAs($user)->patchJson("/api/knowledge/categories/$category", ['name' => 'X'])->assertForbidden();
        $this->actingAs($user)->postJson('/api/knowledge/articles', ['title' => 'x', 'body_md' => 'y'])->assertForbidden();
        $this->actingAs($user)->patchJson("/api/knowledge/articles/$id", ['title' => 'Hacked'])->assertForbidden();
        $this->actingAs($user)->getJson("/api/knowledge/articles/$id/versions")->assertForbidden();
        $this->assertSame('Original', KbArticle::query()->findOrFail($id)->title);

        // Reading endpoints stay open: published article, categories; markdown source is hidden from readers.
        $this->actingAs($user)->getJson('/api/knowledge/categories')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($user)->getJson("/api/knowledge/articles/$id")->assertOk()->assertJsonPath('data.body_md', null);
    }

    public function test_superadmin_is_an_editor(): void
    {
        $super = $this->login(UserRole::Superadmin);
        $id = $this->article($super, ['status' => 'draft']);
        $this->actingAs($super)->getJson("/api/knowledge/articles/$id")->assertOk()->assertJsonPath('data.body_md', 'text');
        $this->actingAs($super)->getJson("/api/knowledge/articles/$id/versions")->assertOk();
    }

    public function test_guest_and_blocked_user_are_rejected(): void
    {
        $this->getJson('/api/knowledge/categories')->assertUnauthorized();
        $this->postJson('/api/knowledge/articles/1/vote', ['helpful' => true])->assertUnauthorized();
        $blocked = User::factory()->blocked()->withRole(UserRole::Admin)->create();
        $this->actingAs($blocked)->getJson('/api/knowledge/articles')->assertForbidden();
    }

    public function test_categories_create_update_validation_and_404(): void
    {
        $admin = $this->login(UserRole::Admin);
        $id = $this->actingAs($admin)->postJson('/api/knowledge/categories', ['name' => 'HR', 'emoji' => '📘', 'position' => '5'])
            ->assertCreated()->assertJsonPath('data.name', 'HR')->json('data.id');
        $this->actingAs($admin)->patchJson("/api/knowledge/categories/$id", ['name' => 'People'])->assertOk()->assertJsonPath('data.name', 'People');
        $this->actingAs($admin)->getJson('/api/knowledge/categories')->assertOk()->assertJsonPath('data.0.name', 'People');

        $this->actingAs($admin)->postJson('/api/knowledge/categories', [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($admin)->postJson('/api/knowledge/categories', ['name' => 'x', 'position' => 'first'])->assertUnprocessable()->assertJsonValidationErrors('position');
        $this->actingAs($admin)->postJson('/api/knowledge/categories', ['name' => 'x', 'position' => 1001])->assertUnprocessable()->assertJsonValidationErrors('position');
        $this->actingAs($admin)->postJson('/api/knowledge/categories', ['name' => str_repeat('n', 121)])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($admin)->patchJson('/api/knowledge/categories/'.($id + 1000), ['name' => 'Ghost'])->assertNotFound();
    }

    public function test_article_store_validation(): void
    {
        $admin = $this->login(UserRole::Admin);
        $this->actingAs($admin)->postJson('/api/knowledge/articles', [])->assertUnprocessable()->assertJsonValidationErrors(['title', 'body_md']);
        $base = ['title' => 't', 'body_md' => 'b'];
        $this->actingAs($admin)->postJson('/api/knowledge/articles', $base + ['category_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->actingAs($admin)->postJson('/api/knowledge/articles', $base + ['status' => 'archived'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->actingAs($admin)->postJson('/api/knowledge/articles', $base + ['tags' => array_fill(0, 21, 't')])->assertUnprocessable()->assertJsonValidationErrors('tags');
        $this->actingAs($admin)->postJson('/api/knowledge/articles', $base + ['audience' => ['type' => 'everyone']])->assertUnprocessable()->assertJsonValidationErrors('audience.type');
        $this->actingAs($admin)->postJson('/api/knowledge/articles', $base + ['audience' => ['type' => 'branches']])->assertUnprocessable()->assertJsonValidationErrors('audience.ids');
        $this->actingAs($admin)->postJson('/api/knowledge/articles', $base + ['audience' => ['type' => 'branches', 'ids' => ['kyiv']]])->assertUnprocessable()->assertJsonValidationErrors('audience.ids.0');
        $this->actingAs($admin)->postJson('/api/knowledge/articles', $base + ['audience' => ['type' => 'roles']])->assertUnprocessable()->assertJsonValidationErrors('audience.roles');
        $this->assertSame(0, KbArticle::query()->count());

        // Default: draft, audience "all", version 1, author set.
        $this->actingAs($admin)->postJson('/api/knowledge/articles', $base)->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.version', 1)->assertJsonPath('data.audience', ['type' => 'all']);
        $this->assertSame($admin->id, KbArticle::query()->sole()->author_id);
    }

    public function test_index_filters_and_string_params(): void
    {
        $admin = $this->login(UserRole::Admin);
        $reader = $this->login(UserRole::Viewer);
        $cat = $this->actingAs($admin)->postJson('/api/knowledge/categories', ['name' => 'Office'])->json('data.id');
        $this->article($admin, ['title' => 'In category', 'category_id' => $cat, 'tags' => ['Parking']]);
        $this->article($admin, ['title' => 'Elsewhere']);

        $this->actingAs($reader)->getJson("/api/knowledge/articles?category_id=$cat")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'In category');
        $this->actingAs($reader)->getJson('/api/knowledge/articles?tag=%20PARKING%20')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($reader)->getJson('/api/knowledge/articles?q=')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($reader)->getJson('/api/knowledge/articles?category_id=abc')->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->actingAs($reader)->getJson('/api/knowledge/articles?q='.str_repeat('q', 101))->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->actingAs($reader)->getJson('/api/knowledge/articles?tag='.str_repeat('t', 41))->assertUnprocessable()->assertJsonValidationErrors('tag');
    }

    public function test_show_returns_sanitized_html_and_404s(): void
    {
        $admin = $this->login(UserRole::Admin);
        $reader = $this->login(UserRole::Viewer);
        $id = $this->article($admin, ['body_md' => "Hi <iframe src=\"https://evil.test\"></iframe>\n\n[d](data:text/html;base64,PHNjcmlwdD4=)"]);

        $html = (string) $this->actingAs($reader)->getJson("/api/knowledge/articles/$id")->assertOk()->json('data.html');
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('data:text/html', $html);
        $this->actingAs($reader)->getJson('/api/knowledge/articles/'.($id + 1000))->assertNotFound();
        $this->actingAs($admin)->getJson('/api/knowledge/articles/'.($id + 1000))->assertNotFound();
    }

    public function test_update_resanitizes_and_creates_versions_only_for_text_changes(): void
    {
        $admin = $this->login(UserRole::Admin);
        $id = $this->article($admin, ['title' => 'V1', 'body_md' => 'first']);

        $html = (string) $this->actingAs($admin)->patchJson("/api/knowledge/articles/$id", ['body_md' => '<script>alert(1)</script>'])->assertOk()
            ->assertJsonPath('data.version', 2)->json('data.html');
        $this->assertStringNotContainsString('<script', $html);

        // Same title again, audience/status change: no new version.
        $this->actingAs($admin)->patchJson("/api/knowledge/articles/$id", ['title' => 'V1', 'audience' => ['type' => 'roles', 'roles' => ['recruiter']], 'status' => 'draft'])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.status', 'draft');
        $this->actingAs($admin)->patchJson("/api/knowledge/articles/$id", ['title' => 'V3'])->assertOk()->assertJsonPath('data.version', 3);

        $this->actingAs($admin)->getJson("/api/knowledge/articles/$id/versions")->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame([1, 2], KbArticleVersion::query()->where('article_id', $id)->orderBy('version')->pluck('version')->all());

        $this->actingAs($admin)->patchJson("/api/knowledge/articles/$id", ['title' => ''])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->actingAs($admin)->patchJson("/api/knowledge/articles/$id", ['category_id' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->actingAs($admin)->patchJson('/api/knowledge/articles/'.($id + 1000), ['title' => 'x'])->assertNotFound();
        $this->actingAs($admin)->getJson('/api/knowledge/articles/'.($id + 1000).'/versions')->assertNotFound();
    }

    public function test_publishing_a_draft_sets_published_at_once(): void
    {
        $admin = $this->login(UserRole::Admin);
        $reader = $this->login(UserRole::Viewer);
        $id = $this->article($admin, ['status' => 'draft']);
        $draft = KbArticle::query()->findOrFail($id);
        $this->assertNull($draft->published_at);

        $this->actingAs($admin)->patchJson("/api/knowledge/articles/$id", ['status' => 'published'])->assertOk();
        $first = $draft->refresh()->published_at;
        $this->assertNotNull($first);
        $this->actingAs($reader)->getJson("/api/knowledge/articles/$id")->assertOk();

        // Unpublishing hides it again (404 for readers, vote too), republishing keeps the original date.
        $this->actingAs($admin)->patchJson("/api/knowledge/articles/$id", ['status' => 'draft'])->assertOk();
        $this->actingAs($reader)->getJson("/api/knowledge/articles/$id")->assertNotFound();
        $this->actingAs($reader)->postJson("/api/knowledge/articles/$id/vote", ['helpful' => true])->assertNotFound();
        $this->travel(2)->days();
        $this->actingAs($admin)->patchJson("/api/knowledge/articles/$id", ['status' => 'published'])->assertOk();
        $this->assertTrue($first->equalTo(KbArticle::query()->findOrFail($id)->published_at));
    }

    public function test_vote_validation_is_one_per_user_and_404s(): void
    {
        $admin = $this->login(UserRole::Admin);
        $a = $this->login(UserRole::Viewer);
        $b = $this->login(UserRole::Recruiter);
        $id = $this->article($admin);

        $this->actingAs($a)->postJson("/api/knowledge/articles/$id/vote", [])->assertUnprocessable()->assertJsonValidationErrors('helpful');
        $this->actingAs($a)->postJson("/api/knowledge/articles/$id/vote", ['helpful' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors('helpful');
        // Booleans arriving as strings ("1"/"0") are accepted.
        $this->actingAs($a)->postJson("/api/knowledge/articles/$id/vote", ['helpful' => '1'])->assertOk()->assertJsonPath('data.my_vote', true);
        $this->actingAs($a)->postJson("/api/knowledge/articles/$id/vote", ['helpful' => '0'])->assertOk()->assertJsonPath('data.my_vote', false);
        $this->actingAs($b)->postJson("/api/knowledge/articles/$id/vote", ['helpful' => true])->assertOk()
            ->assertJsonPath('data.votes', ['helpful' => 1, 'not_helpful' => 1]);
        $this->assertSame(2, KbVote::query()->where('article_id', $id)->count());
        $this->actingAs($admin)->getJson("/api/knowledge/articles/$id")->assertOk()->assertJsonPath('data.my_vote', null);
        $this->actingAs($a)->postJson('/api/knowledge/articles/'.($id + 1000).'/vote', ['helpful' => true])->assertNotFound();
    }

    public function test_audience_uses_employee_branch_and_roles_for_every_endpoint(): void
    {
        $admin = $this->login(UserRole::Admin);
        $kyiv = Branch::query()->create(['name' => 'Kyiv syn', 'status' => 'active']);
        $odesa = Branch::query()->create(['name' => 'Odesa syn', 'status' => 'active']);
        $employee = $this->login(UserRole::Viewer);
        $this->employee(['branch_id' => $odesa->id], $employee);
        $noBranch = $this->login(UserRole::Viewer);

        $kyivOnly = $this->article($admin, ['title' => 'Kyiv', 'audience' => ['type' => 'branches', 'ids' => [$kyiv->id]]]);
        $odesaOnly = $this->article($admin, ['title' => 'Odesa', 'audience' => ['type' => 'branches', 'ids' => [(string) $odesa->id, $odesa->id]]]);
        $viewers = $this->article($admin, ['title' => 'Viewers', 'audience' => ['type' => 'roles', 'roles' => ['viewer', 'viewer']]]);

        $this->actingAs($admin)->getJson("/api/knowledge/articles/$odesaOnly")->assertOk()->assertJsonPath('data.audience', ['type' => 'branches', 'ids' => [$odesa->id]]);
        $this->actingAs($employee)->getJson("/api/knowledge/articles/$odesaOnly")->assertOk();
        $this->actingAs($employee)->getJson("/api/knowledge/articles/$kyivOnly")->assertNotFound();
        $this->actingAs($employee)->postJson("/api/knowledge/articles/$kyivOnly/vote", ['helpful' => true])->assertNotFound();
        $this->actingAs($noBranch)->getJson("/api/knowledge/articles/$odesaOnly")->assertNotFound();
        $this->actingAs($noBranch)->getJson("/api/knowledge/articles/$viewers")->assertOk();
        $this->actingAs($this->login(UserRole::Recruiter))->getJson("/api/knowledge/articles/$viewers")->assertNotFound();
        $titles = array_column((array) $this->actingAs($noBranch)->getJson('/api/knowledge/articles')->json('data'), 'title');
        $this->assertSame(['Viewers'], $titles);
    }
}
