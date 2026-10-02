<?php

declare(strict_types=1);

namespace Tests\Feature\Recruiting;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\Enums\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

/**
 * ?perPage of the Recruiting lists arrives as a query string ("1"): it is cast to an int (meta.per_page), defaults
 * to 50 and is bounded to 1..200 (ListCandidatesRequest, ListVacanciesRequest, PerPageRequest, TimelineRequest).
 * Pins the current behaviour before the pagination refactoring. Synthetic data only.
 */
final class PaginationParamsTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    private User $admin;

    private string $timeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->userWith(UserRole::Admin);
        $branch = Branch::factory()->create();
        $vacancy = $this->vacancyIn($branch);
        $this->vacancyIn($branch);
        $application = $this->applied($vacancy, ['full_name' => 'Alpha Sample', 'phone' => '+380671112233']);
        $this->applied($vacancy, ['full_name' => 'Beta Sample']);
        $this->ingest(Channel::Telegram, '+380671112233');
        $this->ingest(Channel::Telegram, '@stranger_one');
        $this->ingest(Channel::Telegram, '@stranger_two');
        $this->timeline = "/api/candidates/{$application->candidate_id}/timeline";
    }

    /** @return list<string> */
    private function lists(): array
    {
        return ['/api/candidates', '/api/vacancies', '/api/inbox', $this->timeline];
    }

    public function test_per_page_string_is_cast_and_defaults_to_50(): void
    {
        foreach ($this->lists() as $url) {
            $this->actingAs($this->admin)->getJson($url)->assertOk()->assertJsonPath('meta.per_page', 50);
            $this->actingAs($this->admin)->getJson($url.'?perPage=1')->assertOk()
                ->assertJsonPath('meta.per_page', 1)->assertJsonCount(1, 'data');
            $this->actingAs($this->admin)->getJson($url.'?perPage=200')->assertOk()->assertJsonPath('meta.per_page', 200);
        }
    }

    public function test_per_page_out_of_range_or_not_a_number_is_422(): void
    {
        foreach ($this->lists() as $url) {
            foreach (['perPage=0', 'perPage=abc', 'perPage=201'] as $query) {
                $this->actingAs($this->admin)->getJson($url.'?'.$query)
                    ->assertUnprocessable()->assertJsonValidationErrors(['perPage']);
            }
        }
    }
}
