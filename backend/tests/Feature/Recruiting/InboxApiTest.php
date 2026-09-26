<?php

declare(strict_types=1);

namespace Tests\Feature\Recruiting;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Models\Candidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\NavBadgeAssertions;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

final class InboxApiTest extends TestCase
{
    use NavBadgeAssertions, RecruitingFixtures, RefreshDatabase;

    private Branch $north;

    private Branch $south;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->north, $this->south] = Branch::factory()->count(2)->create()->all();
    }

    public function test_nav_badge_counts_the_inbox_in_scope(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $this->ingest(Channel::Telegram, '@stranger_one', ['branch_id' => $this->north->id]);
        $this->ingest(Channel::Whatsapp, '+380931112233', ['branch_id' => $this->south->id]);
        $this->ingest(Channel::Email, 'nobody@example.test', ['author_id' => $recruiter->id]);

        $this->assertBadgeMatchesList($recruiter, 'inbox', '/api/inbox', 2, 'meta.total');
        $this->assertBadgeMatchesList($this->userWith(UserRole::Admin), 'inbox', '/api/inbox', 3, 'meta.total');
        $this->assertBadgeMatchesList($this->userWith(UserRole::Recruiter, [Branch::factory()->create()]), 'inbox', '/api/inbox', 0, 'meta.total');
    }

    public function test_inbox_lists_unmatched_messages_in_scope(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $this->ingest(Channel::Telegram, '@stranger_one', ['branch_id' => $this->north->id]);
        $this->ingest(Channel::Whatsapp, '+380931112233', ['branch_id' => $this->south->id]);
        $this->ingest(Channel::Email, 'nobody@example.test', ['author_id' => $recruiter->id]);
        $known = $this->applied($this->vacancyIn($this->north), ['phone' => '+380671110000']);
        $this->ingest(Channel::Call, '0671110000', ['branch_id' => $this->north->id]);

        $this->actingAs($recruiter)->getJson('/api/inbox')->assertOk()->assertJsonPath('meta.total', 2);
        $this->actingAs($this->userWith(UserRole::Admin))->getJson('/api/inbox?perPage=50')->assertOk()->assertJsonPath('meta.total', 3);
        $this->actingAs($recruiter)->getJson('/api/inbox?perPage=0')->assertUnprocessable();
        $this->assertSame(1, $known->candidate->touchpoints()->where('channel', 'call')->count());
    }

    public function test_link_to_candidate(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $application = $this->applied($this->vacancyIn($this->north));
        $message = $this->ingest(Channel::Viber, '+380931112233', ['branch_id' => $this->north->id]);

        $this->actingAs($recruiter)->postJson("/api/inbox/$message->id/link", ['candidate_id' => $application->candidate_id])
            ->assertOk()
            ->assertJsonPath('data.candidate_id', $application->candidate_id)
            ->assertJsonPath('data.application_id', $application->id);
        $this->assertNotNull($application->fresh()?->last_touch_at);
        $this->actingAs($recruiter)->postJson("/api/inbox/$message->id/link", ['candidate_id' => $application->candidate_id])
            ->assertStatus(409)->assertJsonPath('code', 'already_linked');
    }

    public function test_link_and_create_are_scoped(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $southMessage = $this->ingest(Channel::Viber, '+380931112244', ['branch_id' => $this->south->id]);
        $northMessage = $this->ingest(Channel::Viber, '+380931112255', ['branch_id' => $this->north->id]);
        $foreignCandidate = Candidate::factory()->create();

        $this->actingAs($recruiter)->postJson("/api/inbox/$southMessage->id/link", ['candidate_id' => $foreignCandidate->id])->assertForbidden();
        $this->actingAs($recruiter)->postJson("/api/inbox/$northMessage->id/link", ['candidate_id' => $foreignCandidate->id])->assertForbidden();
        $this->actingAs($this->userWith(UserRole::Viewer, [$this->north]))
            ->postJson("/api/inbox/$northMessage->id/create-candidate", ['full_name' => 'New Person'])->assertForbidden();
        $this->actingAs($recruiter)->postJson("/api/inbox/$northMessage->id/create-candidate", [
            'full_name' => 'New Person', 'vacancy_id' => $this->vacancyIn($this->south)->id,
        ])->assertForbidden();
    }

    public function test_create_candidate_from_message(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $vacancy = $this->vacancyIn($this->north);
        $message = $this->ingest(Channel::Telegram, '@New_Lead', ['branch_id' => $this->north->id]);

        $this->actingAs($recruiter)->postJson("/api/inbox/$message->id/create-candidate", ['full_name' => 'New Lead', 'vacancy_id' => (string) $vacancy->id])
            ->assertCreated()
            ->assertJsonPath('data.telegram_username', 'new_lead')
            ->assertJsonPath('data.source', 'inbox');
        $candidate = Candidate::query()->where('telegram_username', 'new_lead')->firstOrFail();
        $this->assertSame($candidate->id, $message->fresh()?->candidate_id);
        $this->assertSame(1, $candidate->applications()->count());

        // Same contact again → dedupe 409 (no "create anyway"; link the existing candidate instead).
        $again = $this->ingest(Channel::Telegram, '@someone_else', ['branch_id' => $this->north->id]);
        Candidate::factory()->create(['telegram_username' => 'someone_else', 'owner_id' => $recruiter->id]);
        $this->actingAs($recruiter)->postJson("/api/inbox/$again->id/create-candidate", ['full_name' => 'Someone'])
            ->assertStatus(409)->assertJsonPath('code', 'duplicate_candidate')->assertJsonPath('matched_by', 'telegram');
        $this->assertNull($again->fresh()?->candidate_id);
        $this->actingAs($recruiter)->postJson("/api/inbox/$again->id/create-candidate", ['full_name' => 'X'])->assertUnprocessable();
    }
}
