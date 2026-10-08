<?php

declare(strict_types=1);

namespace Tests\Feature\Recruiting;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\GoogleWorkspace\Contracts\Mailer;
use App\Modules\GoogleWorkspace\DTO\OutgoingMail;
use App\Modules\GoogleWorkspace\DTO\SentMail;
use App\Modules\GoogleWorkspace\Enums\MailerState;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Touchpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

/** Synthetic data only: the repository is public. */
final class OfferApiTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    /** @var list<OutgoingMail> */
    private array $sent = [];

    private Branch $branch;

    private Application $application;

    private DocumentTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        $vacancy = $this->vacancyIn($this->branch);
        $this->application = $this->applied($vacancy, ['full_name' => 'Olena Sample', 'email' => 'olena.sample@example.test']);
        $this->template = DocumentTemplate::query()->create([
            'name' => 'Offer', 'category' => 'offer', 'body' => '{ПІБ}: {Посада}, {Зарплата}, {Дата виходу}. {Умови}',
        ]);
        $test = $this;
        $this->app->instance(Mailer::class, new class($test) implements Mailer
        {
            public function __construct(private readonly OfferApiTest $test) {}

            public function state(): MailerState
            {
                return MailerState::Ready;
            }

            public function send(OutgoingMail $mail): SentMail
            {
                $this->test->record($mail);

                return new SentMail('m1', 't1');
            }
        });
    }

    public function record(OutgoingMail $mail): void
    {
        $this->sent[] = $mail;
    }

    public function test_offer_flow_create_send_accept(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->branch]);
        $url = '/api/applications/'.$this->application->id.'/offer';
        $body = ['template_id' => $this->template->id, 'position' => 'Manager', 'salary' => '30000 UAH', 'start_date' => '2026-11-02', 'conditions' => 'Full time'];

        $this->actingAs($recruiter)->getJson('/api/offer-templates')->assertOk()->assertJsonPath('data.0.id', $this->template->id);
        $this->actingAs($recruiter)->postJson($url, $body)->assertUnprocessable()->assertJsonPath('message', 'not_in_offer_stage');
        $this->application->update(['stage_id' => $this->stageAt(6)->id]);

        $this->actingAs($recruiter)->postJson($url, ['position' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['template_id', 'salary']);
        $this->actingAs($recruiter)->postJson($url, $body)->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.content_md', 'Olena Sample: Manager, 30000 UAH, 02.11.2026. Full time');
        $this->actingAs($recruiter)->postJson($url, $body)->assertStatus(409);
        $this->actingAs($recruiter)->postJson($url.'/decision', ['status' => 'accepted'])->assertUnprocessable();

        $this->actingAs($recruiter)->postJson($url.'/send')->assertOk()->assertJsonPath('data.status', 'sent');
        $this->assertCount(1, $this->sent);
        $this->assertSame('olena.sample@example.test', $this->sent[0]->to);
        $this->assertTrue(Touchpoint::query()->where('application_id', $this->application->id)->where('channel', 'email')->exists());

        $this->actingAs($recruiter)->postJson($url.'/decision', ['status' => 'accepted'])->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->actingAs($recruiter)->getJson($url)->assertOk()->assertJsonPath('data.salary', '30000 UAH');
    }

    public function test_salary_is_visible_only_to_writers_and_hiring_manager(): void
    {
        $this->application->update(['stage_id' => $this->stageAt(6)->id]);
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->branch]);
        $this->actingAs($recruiter)->postJson('/api/applications/'.$this->application->id.'/offer', [
            'template_id' => $this->template->id, 'position' => 'Manager', 'salary' => '30000 UAH',
        ])->assertCreated();
        $url = '/api/applications/'.$this->application->id.'/offer';

        $viewer = $this->userWith(UserRole::Viewer, [$this->branch]);
        $otherBranch = $this->userWith(UserRole::Recruiter, [Branch::factory()->create()]);
        $this->actingAs($viewer)->getJson($url)->assertForbidden();
        $this->actingAs($otherBranch)->getJson($url)->assertForbidden();
        $this->actingAs($viewer)->postJson($url.'/send')->assertForbidden();

        $manager = User::factory()->withRole(UserRole::Employee)->create();
        $this->application->vacancy->update(['hiring_manager_id' => $manager->id]);
        $this->actingAs($manager)->getJson($url)->assertOk()->assertJsonPath('data.salary', '30000 UAH');
    }

    /** The sent offer becomes a touchpoint on the application: its text must not leak the salary through the timeline. */
    public function test_sent_offer_text_is_redacted_in_the_timeline_for_non_writers(): void
    {
        $this->application->update(['stage_id' => $this->stageAt(6)->id]);
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->branch]);
        $url = '/api/applications/'.$this->application->id.'/offer';
        $this->actingAs($recruiter)->postJson($url, [
            'template_id' => $this->template->id, 'position' => 'Manager', 'salary' => '30000 UAH',
        ])->assertCreated();
        $this->actingAs($recruiter)->postJson($url.'/send')->assertOk();
        $timeline = '/api/candidates/'.$this->application->candidate_id.'/timeline?channel=email';

        $interviewer = User::factory()->withRole(UserRole::Employee)->create();
        $this->application->interviewers()->sync([$interviewer->id => ['created_at' => now()]]);
        foreach ([$this->userWith(UserRole::Viewer, [$this->branch]), $interviewer] as $reader) {
            $touchpoint = $this->actingAs($reader)->getJson($timeline)->assertOk()
                ->assertJsonPath('data.0.touchpoint.channel', 'email')
                ->json('data.0.touchpoint');
            $this->assertStringNotContainsString('30000 UAH', json_encode($touchpoint, JSON_THROW_ON_ERROR));
            $this->assertNull($touchpoint['body']);
            $this->assertSame('offer', $touchpoint['meta']['kind'] ?? null);
            $this->assertTrue($touchpoint['redacted'] ?? false);
        }

        $this->actingAs($recruiter)->getJson($timeline)->assertOk()
            ->assertJsonPath('data.0.touchpoint.redacted', false);
        $this->assertStringContainsString(
            '30000 UAH',
            (string) $this->actingAs($recruiter)->getJson($timeline)->json('data.0.touchpoint.body'),
        );
    }

    /** Data migration 2026_10_28_100001: offer e-mails sent before meta.kind existed get marked, nothing else does. */
    public function test_backfill_marks_offer_touches_sent_before_the_fix_and_is_idempotent(): void
    {
        $this->application->update(['stage_id' => $this->stageAt(6)->id]);
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->branch]);
        $url = '/api/applications/'.$this->application->id.'/offer';
        $this->actingAs($recruiter)->postJson($url, [
            'template_id' => $this->template->id, 'position' => 'Manager', 'salary' => '30000 UAH',
        ])->assertCreated();
        $this->actingAs($recruiter)->postJson($url.'/send')->assertOk();

        // The touch as the pre-fix code stored it: no meta.kind. Plus an old row with no meta.subject at all.
        $offerTouch = Touchpoint::query()->where('application_id', $this->application->id)->where('channel', 'email')->sole();
        $offerTouch->update(['meta' => ['subject' => 'Оффер: Manager', 'gmail_thread' => 't1']]);
        $legacy = $this->touch(['body' => "Оффер: Manager\n\n30000 UAH", 'meta' => null]);
        // Not offers: another e-mail on the same application, an inbound reply, a touch of an application without a sent offer.
        $plain = $this->touch(['body' => "Interview\n\nTomorrow", 'meta' => ['subject' => 'Interview']]);
        $reply = $this->touch(['direction' => 'in', 'body' => 'Re: Оффер: Manager', 'meta' => ['subject' => 'Оффер: Manager']]);
        $otherApplication = $this->applied($this->vacancyIn($this->branch), ['full_name' => 'Petro Sample', 'email' => 'petro.sample@example.test']);
        $unsent = $this->touch([
            'application_id' => $otherApplication->id,
            'candidate_id' => $otherApplication->candidate_id,
            'meta' => ['subject' => 'Оффер: Draft'],
        ]);

        $migration = require base_path('app/Modules/Recruiting/Database/Migrations/2026_10_28_100001_mark_sent_offer_touchpoints.php');
        $migration->up();
        $migration->up(); // idempotent

        // assertEquals: MySQL JSON stores object keys in its own order (shorter keys first), not insertion order.
        $this->assertEquals(['subject' => 'Оффер: Manager', 'gmail_thread' => 't1', 'kind' => 'offer'], $offerTouch->fresh()?->meta);
        $this->assertEquals(['kind' => 'offer'], $legacy->fresh()?->meta);
        $this->assertEquals(['subject' => 'Interview'], $plain->fresh()?->meta);
        $this->assertEquals(['subject' => 'Оффер: Manager'], $reply->fresh()?->meta);
        $this->assertEquals(['subject' => 'Оффер: Draft'], $unsent->fresh()?->meta);
    }

    /** @param  array<string, mixed>  $attributes */
    private function touch(array $attributes): Touchpoint
    {
        return Touchpoint::query()->create($attributes + [
            'candidate_id' => $this->application->candidate_id, 'application_id' => $this->application->id,
            'channel' => 'email', 'direction' => 'out', 'occurred_at' => now(), 'body' => 'Оффер: Draft', 'via_product' => true,
        ]);
    }

    public function test_template_must_be_an_offer_template(): void
    {
        $this->application->update(['stage_id' => $this->stageAt(6)->id]);
        $other = DocumentTemplate::query()->create(['name' => 'Order', 'category' => 'orders', 'body' => '{ПІБ}']);

        $this->actingAs($this->userWith(UserRole::Admin))->postJson('/api/applications/'.$this->application->id.'/offer', [
            'template_id' => $other->id, 'position' => 'Manager', 'salary' => '1',
        ])->assertUnprocessable()->assertJsonPath('message', 'template_not_offer');
    }
}
