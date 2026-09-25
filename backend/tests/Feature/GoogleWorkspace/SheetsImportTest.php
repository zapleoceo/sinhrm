<?php

declare(strict_types=1);

namespace Tests\Feature\GoogleWorkspace;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Models\SheetImport;
use App\Modules\GoogleWorkspace\Services\SheetsSyncJob;
use App\Modules\Integrations\Models\Integration;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\GoogleFixtures;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

/** Google Sheets import with a faked Sheets v4 API. Rows are invented. */
final class SheetsImportTest extends TestCase
{
    use GoogleFixtures;
    use RecruitingFixtures;
    use RefreshDatabase;

    private const string SHEET_ID = 'fakeSpreadsheetId_0123456789abcdef';

    private const string URL = 'https://docs.google.com/spreadsheets/d/'.self::SHEET_ID.'/edit#gid=0';

    private const array HEADER = ['Позначка часу', 'ПІБ', 'Телефон', 'Email', 'Telegram', 'utm_source', 'Вакансія'];

    private User $superadmin;

    private Vacancy $vacancy;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->configureGoogleClient();
        $this->captureLogs();
        $this->superadmin = User::factory()->withRole(UserRole::Superadmin)->create();
        $this->connectGoogle(GoogleService::Sheets, $this->superadmin->id);
        $this->vacancy = $this->vacancyIn(Branch::factory()->create());
        $this->vacancy->update(['title' => 'Менеджер з продажу']);
    }

    public function test_only_superadmin(): void
    {
        Http::fake();
        $admin = User::factory()->withRole(UserRole::Admin)->create();

        $this->postJson('/api/google/sheets/inspect', ['url' => self::URL])->assertUnauthorized();
        $this->actingAs($admin)->postJson('/api/google/sheets/inspect', ['url' => self::URL])->assertForbidden();
        $this->actingAs($admin)->getJson('/api/google/sheets/imports')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_url_must_be_a_google_spreadsheet(): void
    {
        Http::fake();

        foreach (['https://example.test/spreadsheets/d/'.self::SHEET_ID, 'http://docs.google.com/spreadsheets/d/'.self::SHEET_ID, 'not a url'] as $url) {
            $this->actingAs($this->superadmin)->postJson('/api/google/sheets/inspect', ['url' => $url])
                ->assertStatus(422)->assertJsonValidationErrors(['url']);
        }
        Http::assertNothingSent();
    }

    public function test_inspect_returns_header_preview_and_suggested_mapping(): void
    {
        Http::fake(['sheets.googleapis.com/*' => Http::response(['values' => [self::HEADER, ...$this->rows()]])]);

        $this->actingAs($this->superadmin)->postJson('/api/google/sheets/inspect', ['url' => self::URL])
            ->assertOk()
            ->assertJsonPath('data.spreadsheet_id', self::SHEET_ID)
            ->assertJsonPath('data.headers', self::HEADER)
            ->assertJsonCount(5, 'data.rows')
            ->assertJsonPath('data.suggested', [
                'full_name' => 1, 'phone' => 2, 'email' => 3, 'telegram' => 4, 'utm_source' => 5, 'vacancy' => 6, 'created_at' => 0,
            ]);

        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://sheets.googleapis.com/v4/spreadsheets/'.self::SHEET_ID.'/values/A1%3AZ11'));
    }

    public function test_import_creates_matches_skips_and_reports_per_row(): void
    {
        $existing = Candidate::factory()->create(['full_name' => 'Existing Person', 'phone' => '+380501110000', 'email' => null]);
        Http::fake(['sheets.googleapis.com/*' => Http::sequence()
            ->push(['values' => [self::HEADER, ...$this->rows()]])
            ->push(['values' => $this->rows()])]);

        $response = $this->actingAs($this->superadmin)->postJson('/api/google/sheets/imports', [
            'url' => self::URL,
            'mapping' => ['created_at' => 0, 'full_name' => 1, 'phone' => 2, 'email' => 3, 'telegram' => 4, 'utm_source' => 5, 'vacancy' => 6],
            'auto_sync' => true,
        ])->assertCreated();

        $response->assertJsonPath('report.created', 2)
            ->assertJsonPath('report.matched', 1)
            ->assertJsonPath('report.skipped', 1)
            ->assertJsonPath('report.applied', 2)
            ->assertJsonPath('report.vacancy_unmatched', 1)
            ->assertJsonPath('report.errors', [['row' => 6, 'code' => 'full_name_required']])
            ->assertJsonPath('report.last_row', 6)
            ->assertJsonPath('data.last_row', 6)
            ->assertJsonPath('data.auto_sync', true);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/values/A2%3AZ501'));

        $new = Candidate::query()->where('email', 'ivan.sample@example.test')->firstOrFail();
        $this->assertSame('Іван Приклад', $new->full_name);
        $this->assertSame('import', $new->source->value);
        $this->assertSame(['source' => 'sheet_form'], $new->utm);
        $this->assertSame($this->superadmin->id, $new->created_by);
        $application = Application::query()->where('candidate_id', $new->id)->firstOrFail();
        $this->assertSame($this->vacancy->id, $application->vacancy_id);
        $this->assertSame('2026-09-20', $application->stage_entered_at?->toDateString());
        $this->assertTrue(Application::query()->where('candidate_id', $existing->id)->where('vacancy_id', $this->vacancy->id)->exists());
        $this->assertSame(1, Candidate::query()->where('phone', '+380501110000')->count());
        // Report has no cell values.
        $this->assertStringNotContainsString('ivan.sample', (string) json_encode(SheetImport::query()->firstOrFail()->last_report));
    }

    public function test_rerun_is_incremental_and_dedupes(): void
    {
        Http::fake(['sheets.googleapis.com/*' => Http::sequence()
            ->push(['values' => [self::HEADER, ...$this->rows()]])
            ->push(['values' => array_slice($this->rows(), 0, 2)])
            ->push(['values' => [['2026-09-22 09:00', 'Марія Зразок', '+380 67 222 33 44', '', '', '', '']]])]);

        $id = $this->actingAs($this->superadmin)->postJson('/api/google/sheets/imports', [
            'url' => self::URL,
            'mapping' => ['created_at' => 0, 'full_name' => 1, 'phone' => 2, 'email' => 3],
        ])->assertCreated()->assertJsonPath('report.last_row', 3)->json('data.id');

        $this->actingAs($this->superadmin)->postJson('/api/google/sheets/imports/'.$id.'/run')->assertOk()
            ->assertJsonPath('report.created', 1)
            ->assertJsonPath('report.last_row', 4)
            ->assertJsonPath('report.rows', 1);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/values/A4%3AZ503'));
        $this->assertSame(1, Candidate::query()->where('phone', '+380672223344')->count());
    }

    public function test_update_mapping_and_auto_sync_job(): void
    {
        Http::fake(['sheets.googleapis.com/*' => Http::sequence()
            ->push(['values' => [self::HEADER]])
            ->push(['values' => []])
            ->push(['values' => [['2026-09-23', 'Олег Тестовий', '', 'oleg.test@example.test', '', '', '']]])]);
        $id = $this->actingAs($this->superadmin)->postJson('/api/google/sheets/imports', [
            'url' => self::URL, 'mapping' => ['full_name' => 1],
        ])->assertCreated()->assertJsonPath('report.rows', 0)->json('data.id');

        $this->actingAs($this->superadmin)->patchJson('/api/google/sheets/imports/'.$id, [
            'auto_sync' => true, 'mapping' => ['full_name' => 1, 'email' => 3],
        ])->assertOk()->assertJsonPath('data.auto_sync', true)->assertJsonPath('data.mapping.email', 3);
        $this->actingAs($this->superadmin)->patchJson('/api/google/sheets/imports/'.$id, ['mapping' => ['nope' => 1]])
            ->assertStatus(422);

        $result = $this->app->make(SheetsSyncJob::class)->run(Carbon::now());

        $this->assertSame(['imports' => 1, 'created' => 1, 'matched' => 0, 'errors' => 0, 'failed' => 0], $result);
        $this->assertTrue(Candidate::query()->where('email', 'oleg.test@example.test')->exists());
    }

    public function test_sync_job_does_nothing_when_sheets_is_not_connected(): void
    {
        Http::fake();
        Integration::query()->where('key', 'google_sheets')->update(['status' => 'off']);

        $this->assertSame(['skipped' => 'not_connected'], $this->app->make(SheetsSyncJob::class)->run(Carbon::now()));
        Http::assertNothingSent();
    }

    /** @return list<list<string>> rows 2..6 */
    private function rows(): array
    {
        return [
            ['2026-09-20 10:15', 'Іван Приклад', '050 123 45 67', 'Ivan.Sample@example.test', '@ivan_sample', 'sheet_form', 'Менеджер з продажу'],
            ['2026-09-21', 'Existing Person Again', '+38 (050) 111-00-00', '', '', '', 'менеджер з продажу'],
            ['', '', '', '', '', '', ''],
            ['2026-09-21', 'Петро Зразковий', '', 'petro.sample@example.test', '', '', 'Unknown vacancy'],
            ['2026-09-21', '', '', 'no.name@example.test', '', '', ''],
        ];
    }
}
