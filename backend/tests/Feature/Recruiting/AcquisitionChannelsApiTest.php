<?php

declare(strict_types=1);

namespace Tests\Feature\Recruiting;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\DTO\CandidateData;
use App\Modules\Recruiting\Enums\AddedVia;
use App\Modules\Recruiting\Models\AcquisitionChannel;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Services\CandidateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

/** Acquisition channels (tz3): dictionary authz, UTM precedence, how-added, source→channel migration, report math. */
final class AcquisitionChannelsApiTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function channel(string $code): AcquisitionChannel
    {
        return AcquisitionChannel::query()->where('code', $code)->firstOrFail();
    }

    public function test_dictionary_authz_and_validation(): void
    {
        $this->getJson('/api/acquisition-channels')->assertUnauthorized();
        $recruiter = $this->userWith(UserRole::Recruiter);
        $admin = $this->userWith(UserRole::Admin);

        // Seeded from the old source enum (+ common channels); everyone reads, no rules/costs for non-managers.
        $list = $this->actingAs($recruiter)->getJson('/api/acquisition-channels')->assertOk()->json('data');
        $this->assertContains('work_ua', array_column($list, 'code'));
        $this->assertNotContains('manual', array_column($list, 'code'), '"manual" is how-added, not a channel');
        $this->assertArrayNotHasKey('utm_rules', $list[0]);
        $this->actingAs($recruiter)->postJson('/api/acquisition-channels', ['code' => 'x', 'name' => 'X'])->assertForbidden();

        $id = $this->actingAs($admin)->postJson('/api/acquisition-channels', ['code' => 'Jooble', 'name' => 'Jooble', 'type' => 'job_board'])
            ->assertCreated()->assertJsonPath('data.code', 'jooble')->json('data.id');
        $this->actingAs($admin)->postJson('/api/acquisition-channels', ['code' => 'jooble', 'name' => 'Dup'])->assertUnprocessable()->assertJsonPath('code', 'channel_code_taken');
        $this->actingAs($admin)->postJson("/api/acquisition-channels/$id/utm-rules", [])->assertUnprocessable()->assertJsonPath('code', 'empty_utm_rule');
        $rule = $this->actingAs($admin)->postJson("/api/acquisition-channels/$id/utm-rules", ['utm_source' => ' JOOBLE '])->assertCreated()
            ->assertJsonPath('data.utm_rules.0.utm_source', 'jooble')->json('data.utm_rules.0.id');
        $this->actingAs($admin)->postJson("/api/acquisition-channels/$id/costs", ['period_start' => '2026-10-01', 'period_end' => '2026-09-01', 'amount' => 10])->assertUnprocessable();
        $this->actingAs($admin)->postJson("/api/acquisition-channels/$id/costs", ['period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'amount' => 3100])
            ->assertCreated()->assertJsonPath('data.costs.0.amount', 3100);
        $this->actingAs($admin)->postJson('/api/acquisition-channels/resolve', ['utm_source' => 'jooble'])->assertOk()->assertJsonPath('data.channel_id', $id);
        $this->actingAs($recruiter)->deleteJson("/api/acquisition-channels/utm-rules/$rule")->assertForbidden();
        $this->actingAs($admin)->deleteJson("/api/acquisition-channels/utm-rules/$rule")->assertNoContent();
        $this->actingAs($admin)->patchJson("/api/acquisition-channels/$id", ['active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->assertNotContains('jooble', array_column($this->actingAs($recruiter)->getJson('/api/acquisition-channels')->json('data'), 'code'));
    }

    public function test_utm_precedence_on_candidate_creation(): void
    {
        $admin = $this->userWith(UserRole::Admin);
        $meta = $this->channel('meta_ads');
        $insta = $this->channel('instagram');
        // Seeded: instagram → instagram; instagram + paid → meta_ads. Add a campaign rule for the referral channel.
        $referral = $this->channel('referral');
        $this->actingAs($admin)->postJson("/api/acquisition-channels/{$referral->id}/utm-rules", ['utm_source' => 'instagram', 'utm_medium' => 'paid', 'utm_campaign' => 'friends'])->assertCreated();

        $make = function (array $body) use ($admin): Candidate {
            $id = $this->actingAs($admin)->postJson('/api/candidates', $body + ['full_name' => 'Synthetic '.uniqid()])->assertCreated()->json('data.id');

            return Candidate::query()->findOrFail($id);
        };
        // More specific rule wins: source+medium+campaign > source+medium > source.
        $this->assertSame($referral->id, $make(['utm' => ['utm_source' => 'Instagram', 'utm_medium' => 'paid', 'utm_campaign' => 'friends']])->channel_id);
        $this->assertSame($meta->id, $make(['utm' => ['utm_source' => 'instagram', 'utm_medium' => 'PAID']])->channel_id);
        $this->assertSame($insta->id, $make(['utm' => ['source' => 'instagram']])->channel_id, 'keys without the utm_ prefix work too');
        // An explicit channel beats UTM; an inactive one is refused.
        $picked = $make(['channel_id' => $this->channel('job_fair')->id, 'utm' => ['utm_source' => 'instagram']]);
        $this->assertSame($this->channel('job_fair')->id, $picked->channel_id);
        $this->channel('agency')->update(['active' => false]);
        $this->actingAs($admin)->postJson('/api/candidates', ['full_name' => 'Nope', 'channel_id' => $this->channel('agency')->id])
            ->assertUnprocessable()->assertJsonPath('code', 'channel_inactive');
        // No UTM: the legacy source code maps to its channel; "manual" has none. How-added is manual for the form.
        $bySource = $make(['source' => 'djinni']);
        $this->assertSame($this->channel('djinni')->id, $bySource->channel_id);
        $this->assertSame(AddedVia::Manual, $bySource->added_via);
        $this->assertNull($make([])->channel_id);
        // Unknown UTM falls back to the source.
        $this->assertSame($this->channel('dou')->id, $make(['source' => 'dou', 'utm' => ['utm_source' => 'newsletter']])->channel_id);

        // API shape and the channel filter; the candidate's channel can be corrected explicitly.
        $this->actingAs($admin)->getJson("/api/candidates/{$bySource->id}")->assertOk()
            ->assertJsonPath('data.source', 'djinni')->assertJsonPath('data.channel.code', 'djinni')->assertJsonPath('data.added_via', 'manual');
        $this->actingAs($admin)->getJson('/api/candidates?channel_id='.$meta->id)->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->patchJson("/api/candidates/{$bySource->id}", ['channel_id' => $this->channel('linkedin')->id])->assertOk()
            ->assertJsonPath('data.channel.code', 'linkedin');
    }

    public function test_machine_paths_store_how_added(): void
    {
        $service = $this->app->make(CandidateService::class);
        $sheets = $service->createOrMatch(null, CandidateData::fromArray([
            'full_name' => 'Sheet Person', 'email' => 'sheet.person@example.test', 'source' => 'work_ua',
        ])->withAddedVia(AddedVia::Sheets));
        $this->assertSame(AddedVia::Sheets, $sheets->candidate->added_via);
        $this->assertSame($this->channel('work_ua')->id, $sheets->candidate->channel_id);
        $mail = $service->createOrMatch(null, new CandidateData(fullName: 'Mail Person', email: 'mail.person@example.test', addedVia: AddedVia::Mail, utm: ['utm_source' => 'linkedin']));
        $this->assertSame(AddedVia::Mail, $mail->candidate->added_via);
        $this->assertSame($this->channel('linkedin')->id, $mail->candidate->channel_id);
        $default = $service->createOrMatch(null, new CandidateData(fullName: 'Import Person', phone: '0671234567'));
        $this->assertSame(AddedVia::Import, $default->candidate->added_via);
    }

    public function test_source_to_channel_data_migration(): void
    {
        $rows = ['work_ua' => null, 'manual' => null, 'import' => null, 'inbox' => null, 'linkedin' => null, 'site' => null];
        foreach (array_keys($rows) as $i => $source) {
            $rows[$source] = Candidate::factory()->create(['source' => $source, 'email' => "m$i@example.test"])->id;
        }
        $clipped = Candidate::factory()->create(['source' => 'dou', 'email' => 'clip@example.test']);
        DB::table('candidate_profile_urls')->insert(['candidate_id' => $clipped->id, 'site' => 'dou', 'url' => 'https://jobs.dou.ua/users/synthetic', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('candidates')->update(['channel_id' => null, 'added_via' => null]);

        $migration = require base_path('app/Modules/Recruiting/Database/Migrations/2026_10_06_200002_seed_channels_from_sources.php');
        $migration->up();
        $migration->up(); // re-runnable: no duplicate channels or rules

        $get = static fn (int $id): object => DB::table('candidates')->where('id', $id)->first(['channel_id', 'added_via']) ?? (object) [];
        $this->assertSame($this->channel('work_ua')->id, (int) $get($rows['work_ua'])->channel_id);
        $this->assertSame($this->channel('linkedin')->id, (int) $get($rows['linkedin'])->channel_id);
        $this->assertSame($this->channel('site')->id, (int) $get($rows['site'])->channel_id);
        $this->assertNull($get($rows['manual'])->channel_id);
        $this->assertSame('manual', $get($rows['manual'])->added_via);
        $this->assertSame('manual', $get($rows['inbox'])->added_via);
        $this->assertSame('import', $get($rows['import'])->added_via);
        $this->assertSame('extension', $get($clipped->id)->added_via);
        $this->assertSame($this->channel('dou')->id, (int) $get($clipped->id)->channel_id);
        $this->assertNull($get($rows['work_ua'])->added_via, 'unknown how-added stays null');
        $this->assertSame(1, AcquisitionChannel::query()->where('code', 'work_ua')->count());
        $this->assertSame(1, DB::table('channel_utm_rules')->where('utm_source', 'work.ua')->count());
    }

    public function test_channel_report_math_and_vacancy_sources(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();
        $admin = $this->userWith(UserRole::Admin);
        $recruiter = $this->userWith(UserRole::Recruiter, [$branch]);
        $vacancy = $this->vacancyIn($branch, $recruiter);
        $foreign = $this->vacancyIn($other);
        $workUa = $this->channel('work_ua');
        $djinni = $this->channel('djinni');
        $hire = $this->defaultPipeline()->stages->first(fn ($s) => $s->isHire());
        // work_ua: 4 candidates, 1 hired; djinni: 1 candidate, hired; one in another branch.
        foreach (range(1, 4) as $i) {
            $app = $this->applied($vacancy, ['channel_id' => $workUa->id, 'added_via' => 'mail', 'email' => "w$i@example.test"]);
            if ($i === 1) {
                $app->update(['stage_id' => $hire->id, 'status' => 'hired']);
            }
        }
        $this->applied($vacancy, ['channel_id' => $djinni->id, 'added_via' => 'extension', 'email' => 'd@example.test'])->update(['stage_id' => $hire->id, 'status' => 'hired']);
        $this->applied($foreign, ['channel_id' => $djinni->id, 'email' => 'f@example.test']);
        // Costs: October 3100 for 31 days → 20 days in the report range (1–20 Oct) = 2000; a September row is outside.
        DB::table('acquisition_channel_costs')->insert([
            ['channel_id' => $workUa->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'amount' => 3100, 'currency' => 'UAH', 'created_at' => now(), 'updated_at' => now()],
            ['channel_id' => $workUa->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'amount' => 999, 'currency' => 'UAH', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $rows = $this->rowsBy($this->actingAs($admin)->getJson('/api/reports/catalog/channel_effectiveness?from=2026-10-01&to=2026-10-20')->assertOk(), 'channel');
        $this->assertSame(4, $rows['Work.ua']['candidates']);
        $this->assertSame(1, $rows['Work.ua']['hired']);
        $this->assertEquals(25.0, $rows['Work.ua']['conversion_pct']);
        $this->assertEquals(2000.0, $rows['Work.ua']['cost']);
        $this->assertEquals(2000.0, $rows['Work.ua']['cost_per_hire']);
        $this->assertSame(2, $rows['Djinni']['candidates'], 'admin sees every branch');
        $this->assertNull($rows['Djinni']['cost']);
        $this->assertSame(4, $rows['Work.ua']['applications']);

        // The recruiter: own branch only, and never costs.
        $mine = $this->rowsBy($this->actingAs($recruiter)->getJson('/api/reports/catalog/channel_effectiveness?from=2026-10-01&to=2026-10-20')->assertOk(), 'channel');
        $this->assertSame(1, $mine['Djinni']['candidates']);
        $this->assertNull($mine['Work.ua']['cost']);
        $this->assertNull($mine['Work.ua']['cost_per_hire']);

        // Vacancy card block: channel × how added with shares.
        $sources = $this->actingAs($recruiter)->getJson("/api/vacancies/{$vacancy->id}/sources")->assertOk()->json('data');
        $this->assertSame(['channel_id' => $workUa->id, 'name' => 'Work.ua', 'added_via' => 'mail', 'count' => 4], array_intersect_key($sources[0], array_flip(['channel_id', 'name', 'added_via', 'count'])));
        $this->assertEquals(80.0, $sources[0]['share_pct']);
        $this->actingAs($recruiter)->getJson("/api/vacancies/{$foreign->id}/sources")->assertForbidden();
    }

    /**
     * Report rows keyed by a column.
     *
     * @param  TestResponse<Response>  $response
     * @return array<string, array<string, mixed>>
     */
    private function rowsBy(TestResponse $response, string $key): array
    {
        $rows = $response->json('data.rows');
        $this->assertIsArray($rows);
        $out = [];
        foreach ($rows as $row) {
            $this->assertIsArray($row);
            $out[(string) $row[$key]] = $row;
        }

        return $out;
    }
}
