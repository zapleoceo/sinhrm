<?php

declare(strict_types=1);

namespace Tests\Feature\MailAgent;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\MailAgent\Models\MailMessage;
use App\Modules\MailAgent\Models\SenderRule;
use App\Modules\MailAgent\Models\UnknownSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\NavBadgeAssertions;
use Tests\TestCase;

/** Admin → Mail: rules CRUD, unknown-senders queue, status, processed log. Synthetic addresses only. */
final class MailAdminApiTest extends TestCase
{
    use NavBadgeAssertions, RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superadmin = User::factory()->withRole(UserRole::Superadmin)->create();
    }

    public function test_only_superadmin(): void
    {
        $admin = User::factory()->withRole(UserRole::Admin)->create();
        $urls = ['/api/mail/status', '/api/mail/rules', '/api/mail/unknown-senders', '/api/mail/messages'];
        foreach ($urls as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        foreach ($urls as $url) {
            $this->actingAs($admin)->getJson($url)->assertForbidden();
        }
        $this->actingAs($admin)->postJson('/api/mail/rules', ['pattern' => '@x.example.test', 'kind' => 'ignore'])->assertForbidden();
    }

    public function test_rules_crud(): void
    {
        $id = $this->actingAs($this->superadmin)->postJson('/api/mail/rules', ['pattern' => ' @Jobs.Example.Test ', 'kind' => 'job_board'])
            ->assertCreated()
            ->assertJsonPath('data.pattern', '@jobs.example.test')
            ->assertJsonPath('data.parser', 'generic')
            ->assertJsonPath('data.hits', 0)
            ->json('data.id');

        $this->actingAs($this->superadmin)->postJson('/api/mail/rules', ['pattern' => '@jobs.example.test', 'kind' => 'ignore'])
            ->assertStatus(409)->assertJsonPath('code', 'duplicate_rule');
        $this->actingAs($this->superadmin)->postJson('/api/mail/rules', ['pattern' => 'not an address', 'kind' => 'spam'])
            ->assertStatus(422)->assertJsonValidationErrors(['pattern', 'kind']);

        $this->actingAs($this->superadmin)->patchJson('/api/mail/rules/'.$id, ['parser' => 'work_ua'])
            ->assertOk()->assertJsonPath('data.parser', 'work_ua')->assertJsonPath('data.kind', 'job_board');
        // A non-job-board rule has no parser.
        $this->actingAs($this->superadmin)->patchJson('/api/mail/rules/'.$id, ['kind' => 'newsletter'])
            ->assertOk()->assertJsonPath('data.parser', null);

        $this->actingAs($this->superadmin)->postJson('/api/mail/rules', ['pattern' => 'hr@partner.example.test', 'kind' => 'colleague'])->assertCreated();
        // Order depends on DB collation (Postgres ignores '@' when sorting, SQLite does not) — assert membership only.
        $patterns = $this->actingAs($this->superadmin)->getJson('/api/mail/rules')->assertOk()->assertJsonCount(2, 'data')
            ->json('data.*.pattern');
        $this->assertEqualsCanonicalizing(['@jobs.example.test', 'hr@partner.example.test'], $patterns);

        $this->actingAs($this->superadmin)->deleteJson('/api/mail/rules/'.$id)->assertNoContent();
        $this->assertNull(SenderRule::query()->find($id));
    }

    public function test_assign_unknown_sender_creates_a_rule_and_clears_the_queue(): void
    {
        $one = $this->unknown('notify@board.example.test', 5);
        $this->unknown('alerts@mail.board.example.test', 2);
        $other = $this->unknown('person@elsewhere.example.test', 1);
        $this->assertBadgeMatchesList($this->superadmin, 'mail_unknown', '/api/mail/unknown-senders', 3);
        $this->assertArrayNotHasKey('mail_unknown', $this->badgesOf(User::factory()->withRole(UserRole::Admin)->create()));

        $this->actingAs($this->superadmin)->getJson('/api/mail/unknown-senders')->assertOk()
            ->assertJsonPath('data.0.email', 'notify@board.example.test')
            ->assertJsonPath('data.0.sample_subject', 'Synthetic subject');

        $this->actingAs($this->superadmin)->postJson('/api/mail/unknown-senders/'.$one->id.'/assign', [
            'kind' => 'job_board', 'parser' => 'robota_ua', 'scope' => 'domain',
        ])->assertCreated()->assertJsonPath('data.pattern', '@board.example.test')->assertJsonPath('data.parser', 'robota_ua');

        // The whole domain (incl. subdomain) left the queue; others stay.
        $this->assertSame([$other->id], UnknownSender::query()->pluck('id')->all());

        $this->actingAs($this->superadmin)->postJson('/api/mail/unknown-senders/'.$other->id.'/assign', ['kind' => 'candidate'])
            ->assertCreated()->assertJsonPath('data.pattern', 'person@elsewhere.example.test')->assertJsonPath('data.parser', null);
        $this->assertSame(0, UnknownSender::query()->count());
    }

    public function test_dismiss_and_status_and_messages(): void
    {
        $sender = $this->unknown('someone@example.test', 1);
        $this->actingAs($this->superadmin)->deleteJson('/api/mail/unknown-senders/'.$sender->id)->assertNoContent();
        MailMessage::query()->create([
            'gmail_id' => 'g1', 'received_at' => Carbon::now(), 'sender' => 'a@b.example.test', 'subject' => 'S', 'outcome' => 'skipped',
        ]);

        $this->actingAs($this->superadmin)->getJson('/api/mail/status')->assertOk()
            ->assertJsonPath('data.connection.connected', false)
            ->assertJsonPath('data.last_sync', null)
            ->assertJsonPath('data.counts', ['rules' => 0, 'unknown' => 0, 'processed_24h' => 1]);
        $this->actingAs($this->superadmin)->getJson('/api/mail/messages')->assertOk()
            ->assertJsonPath('data.0.outcome', 'skipped')
            ->assertJsonMissingPath('data.0.body');
    }

    private function unknown(string $email, int $count): UnknownSender
    {
        return UnknownSender::query()->create([
            'email' => $email,
            'sample_subject' => 'Synthetic subject',
            'first_seen_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
            'count' => $count,
        ]);
    }
}
