<?php

declare(strict_types=1);

namespace Tests\Feature\Recruiting;

use App\Modules\Ai\Models\AiRequest;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Directory\Models\City;
use App\Modules\Directory\Models\VacancyCategory;
use App\Modules\Recruiting\Ai\VacancyTextPrompt;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Models\VacancyTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\Support\AiFixtures;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

/** Full vacancy form, templates, the «active» rule, public salary and AI drafts. Synthetic data only. */
final class VacancyFormApiTest extends TestCase
{
    use AiFixtures, RecruitingFixtures, RefreshDatabase;

    private Branch $north;

    private Branch $south;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake(syncWithCarbon: true);
        [$this->north, $this->south] = Branch::factory()->count(2)->create()->all();
    }

    /** @return array<string, mixed> */
    private function fullForm(): array
    {
        return [
            'title' => 'Sales Manager',
            'branch_id' => $this->north->id,
            'category_id' => VacancyCategory::factory()->create()->id,
            'city_id' => City::factory()->create()->id,
            'country' => 'UA',
            'employment_type' => 'full_time',
            'work_format' => 'hybrid',
            'experience_level' => '1_3',
            'education_level' => 'higher',
            'salary_min' => '20000',
            'salary_max' => 30000,
            'salary_currency' => 'USD',
            'salary_visible' => true,
            'languages' => [['lang' => 'en', 'level' => 'B2']],
            'requirements' => "- CRM\n- **English**",
            'responsibilities' => '1. Calls',
            'additional_info' => 'Remote Fridays',
            'external_postings' => [['site' => 'work_ua', 'url' => 'https://example.test/ad/1', 'date' => '2026-10-20']],
        ];
    }

    public function test_full_form_is_saved_and_returned(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);

        $data = $this->actingAs($recruiter)->postJson('/api/vacancies', $this->fullForm())->assertCreated()->json('data');

        $this->assertSame('hybrid', $data['work_format']);
        $this->assertEquals(20000, $data['salary_min']);
        $this->assertSame('USD', $data['salary_currency']);
        $this->assertTrue($data['salary_visible']);
        $this->assertSame([['lang' => 'en', 'level' => 'B2']], $data['languages']);
        $this->assertSame('work_ua', $data['external_postings'][0]['site']);
        $this->assertNotNull($data['category']);
        $this->assertFalse($data['is_active'], 'open but not published');

        $this->actingAs($recruiter)->patchJson("/api/vacancies/{$data['id']}", ['languages' => [], 'salary_visible' => false])->assertOk()
            ->assertJsonPath('data.languages', [])->assertJsonPath('data.salary_visible', false)
            ->assertJsonPath('data.work_format', 'hybrid');
    }

    public function test_validation_errors_are_per_field(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $bad = [
            'title' => 'X', 'branch_id' => $this->north->id,
            'salary_min' => 500, 'salary_max' => 100,
            'work_format' => 'moon',
            'salary_currency' => 'BTC',
            'languages' => [['lang' => 'en', 'level' => 'Z9'], ['lang' => 'en', 'level' => 'B1']],
            'external_postings' => [['site' => 'work_ua', 'url' => 'javascript:alert(1)']],
            'category_id' => VacancyCategory::factory()->disabled()->create()->id,
        ];

        $this->actingAs($recruiter)->postJson('/api/vacancies', $bad)->assertUnprocessable()
            ->assertJsonValidationErrors([
                'salary_max', 'work_format', 'salary_currency', 'languages.0.level', 'languages.1.lang',
                'external_postings.0.url', 'category_id',
            ]);
    }

    public function test_scope_is_unchanged_for_the_new_fields(): void
    {
        $vacancy = $this->vacancyIn($this->south);
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $viewer = $this->userWith(UserRole::Viewer, [$this->north]);

        $this->actingAs($recruiter)->patchJson("/api/vacancies/{$vacancy->id}", ['salary_min' => 1])->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/vacancies', $this->fullForm())->assertForbidden();
        $this->actingAs($viewer)->getJson('/api/vacancy-templates')->assertForbidden();
    }

    public function test_options_endpoint_lists_codes(): void
    {
        $this->actingAs($this->userWith(UserRole::Viewer))->getJson('/api/vacancy-options')->assertOk()
            ->assertJsonPath('data.work_formats', ['office', 'remote', 'hybrid'])
            ->assertJsonPath('data.currencies', ['UAH', 'USD', 'EUR'])
            ->assertJsonPath('data.language_levels.6', 'native');
    }

    public function test_template_create_list_delete_keeps_only_content_keys(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $form = $this->fullForm();

        $id = $this->actingAs($recruiter)->postJson('/api/vacancy-templates', ['name' => ' Sales ', 'data' => $form])
            ->assertCreated()->assertJsonPath('data.name', 'Sales')->json('data.id');
        $template = VacancyTemplate::query()->findOrFail($id);
        $this->assertArrayNotHasKey('branch_id', $template->data);
        $this->assertSame('hybrid', $template->data['work_format']);

        $this->actingAs($recruiter)->getJson('/api/vacancy-templates')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->actingAs($recruiter)->postJson('/api/vacancy-templates', ['name' => 'x', 'data' => ['work_format' => 'moon']])
            ->assertUnprocessable()->assertJsonValidationErrors(['data.work_format']);
        $this->actingAs($recruiter)->deleteJson("/api/vacancy-templates/{$id}")->assertNoContent();
        $this->assertSame(0, VacancyTemplate::query()->count());
    }

    public function test_template_rename_and_delete_only_by_author_or_admin(): void
    {
        $author = $this->userWith(UserRole::Recruiter, [$this->north]);
        $other = $this->userWith(UserRole::Recruiter, [$this->north]);
        $admin = $this->userWith(UserRole::Admin);
        $id = $this->actingAs($author)->postJson('/api/vacancy-templates', ['name' => 'Sales', 'data' => ['title' => 'Sales']])
            ->assertCreated()->assertJsonPath('data.can_manage', true)->json('data.id');

        $this->actingAs($other)->getJson('/api/vacancy-templates')->assertOk()
            ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.can_manage', false);
        $this->actingAs($other)->patchJson("/api/vacancy-templates/{$id}", ['name' => 'Mine'])->assertForbidden();
        $this->actingAs($other)->deleteJson("/api/vacancy-templates/{$id}")->assertForbidden();

        $this->actingAs($author)->patchJson("/api/vacancy-templates/{$id}", ['name' => ' Sales 2 '])->assertOk()
            ->assertJsonPath('data.name', 'Sales 2')->assertJsonPath('data.data.title', 'Sales');
        $this->actingAs($admin)->getJson('/api/vacancy-templates')->assertOk()->assertJsonPath('data.0.can_manage', true);
        $this->actingAs($admin)->patchJson("/api/vacancy-templates/{$id}", ['name' => 'Sales 3'])->assertOk();
        $this->actingAs($admin)->deleteJson("/api/vacancy-templates/{$id}")->assertNoContent();
    }

    public function test_legacy_template_without_author_is_managed_by_admins_only(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $admin = $this->userWith(UserRole::Superadmin);
        $legacy = VacancyTemplate::query()->create(['name' => 'Old', 'data' => ['title' => 'Old']]);

        $this->actingAs($recruiter)->getJson('/api/vacancy-templates')->assertOk()->assertJsonPath('data.0.can_manage', false);
        $this->actingAs($recruiter)->patchJson("/api/vacancy-templates/{$legacy->id}", ['name' => 'x'])->assertForbidden();
        $this->actingAs($recruiter)->deleteJson("/api/vacancy-templates/{$legacy->id}")->assertForbidden();
        $this->actingAs($admin)->deleteJson("/api/vacancy-templates/{$legacy->id}")->assertNoContent();
    }

    public function test_vacancy_text_checks_category_branch_and_scope(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $facts = ['section' => 'requirements', 'title' => 'Sales Manager'];

        $this->actingAs($recruiter)->postJson('/api/vacancy-text', [...$facts, 'category_id' => 999999, 'branch_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors(['category_id', 'branch_id']);
        $this->actingAs($recruiter)->postJson('/api/vacancy-text', [...$facts, 'branch_id' => $this->south->id])
            ->assertForbidden()->assertJsonPath('code', 'vacancy_out_of_scope');
    }

    public function test_active_rule_scope_filter_count_and_resource(): void
    {
        $active = $this->vacancyIn($this->north);
        $active->update(['published' => true]);
        $this->vacancyIn($this->north);
        $this->vacancyIn($this->north)->update(['published' => true, 'status' => 'closed']);
        $this->vacancyIn($this->south)->update(['published' => true]);
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);

        $this->assertSame(2, Vacancy::query()->active()->count());
        $this->actingAs($recruiter)->getJson('/api/vacancies')->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.active_count', 1);
        foreach (['active=1', 'active=true'] as $q) {
            $this->actingAs($recruiter)->getJson("/api/vacancies?$q")->assertOk()
                ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $active->id)->assertJsonPath('data.0.is_active', true);
        }
        $this->actingAs($recruiter)->getJson('/api/vacancies?active=maybe')->assertUnprocessable();
        // The public career page uses the same rule.
        $this->getJson('/api/public/vacancies')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_public_salary_only_when_visible_and_sections_are_sanitized(): void
    {
        $vacancy = $this->vacancyIn($this->north);
        $vacancy->update([
            'published' => true, 'slug' => 'sales-1', 'salary_min' => 1000, 'salary_max' => 2000, 'salary_currency' => 'EUR',
            'salary_visible' => false, 'requirements' => "<script>alert(1)</script>\n\n**Bold**",
        ]);

        $this->getJson('/api/public/vacancies/sales-1')->assertOk()->assertJsonPath('data.salary', null);
        $html = (string) $this->getJson('/api/public/vacancies/sales-1')->json('data.requirements_html');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('<strong>Bold</strong>', $html);

        $vacancy->update(['salary_visible' => true]);
        $this->getJson('/api/public/vacancies')->assertOk()
            ->assertJsonPath('data.0.salary', ['min' => 1000, 'max' => 2000, 'currency' => 'EUR']);
    }

    public function test_ai_text_draft_goes_through_the_ai_service(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $other = $this->userWith(UserRole::Recruiter, [$this->north]);
        $facts = ['section' => 'requirements', 'title' => 'Sales Manager', 'branch_id' => $this->north->id, 'employment_type' => 'full_time'];

        $this->actingAs($recruiter)->postJson('/api/vacancy-text', $facts)->assertStatus(422)->assertJsonPath('code', 'ai_disabled');

        $this->enableAi();
        $this->fakeBroker([[self::doneAnswer(['text' => "- CRM\n- <b>English</b>"])]]);
        $data = $this->actingAs($recruiter)->postJson('/api/vacancy-text', $facts)->assertOk()
            ->assertJsonPath('data.status', 'done')->assertJsonPath('data.text', "- CRM\n- English")->json('data');
        $row = AiRequest::query()->findOrFail($data['request_id']);
        $this->assertSame(VacancyTextPrompt::VERSION, $row->prompt_version);
        $this->assertSame('chat:fast', $row->capability);

        $this->actingAs($recruiter)->getJson("/api/vacancy-text/{$data['request_id']}")->assertOk()
            ->assertJsonPath('data.text', "- CRM\n- English");
        $this->actingAs($other)->getJson("/api/vacancy-text/{$data['request_id']}")->assertNotFound();
        $this->actingAs($recruiter)->postJson('/api/vacancy-text', ['section' => 'other', 'title' => ''])->assertUnprocessable()
            ->assertJsonValidationErrors(['section', 'title']);
    }

    public function test_vacancy_text_prompt_is_stable_and_personal_data_free(): void
    {
        $input = ['section' => 'requirements', 'title' => 'Tutor', 'category' => 'Education', 'branch' => 'North'];
        $a = VacancyTextPrompt::build($input);
        $b = VacancyTextPrompt::build(['section' => 'description', 'title' => 'Driver']);

        $this->assertSame($a->system, $b->system, 'stable prefix: facts go to the user message only');
        $this->assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2}/', $a->system);
        $this->assertStringContainsString('"title":"Tutor"', $a->user);
        $this->assertSame('insufficient_data', (new VacancyTextPrompt)->skipReason(['title' => ' ']));
    }
}
