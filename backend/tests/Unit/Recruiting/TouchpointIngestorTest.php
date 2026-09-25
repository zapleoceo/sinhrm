<?php

declare(strict_types=1);

namespace Tests\Unit\Recruiting;

use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Touchpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

/** MatchingTouchpointIngestor against the real DB (matching and dedupe are SQL). */
final class TouchpointIngestorTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    public function test_matches_by_phone_email_and_telegram(): void
    {
        $application = $this->applied(
            $this->vacancyIn(Branch::factory()->create()),
            ['phone' => '+380671234567', 'email' => 'match@example.test', 'telegram_username' => 'match_me'],
        );

        foreach ([[Channel::Call, '067-123-45-67'], [Channel::Email, 'MATCH@example.test'], [Channel::Telegram, '@Match_Me']] as [$channel, $contact]) {
            $touch = $this->ingest($channel, $contact);
            $this->assertSame($application->candidate_id, $touch->candidate_id, $channel->value);
            $this->assertSame($application->id, $touch->application_id);
            $this->assertFalse($touch->via_product);
            $this->assertSame($contact, $touch->contact());
        }
        $this->assertNotNull($application->fresh()?->last_touch_at);
    }

    public function test_unmatched_stays_in_inbox_and_does_not_touch_applications(): void
    {
        $application = $this->applied($this->vacancyIn(Branch::factory()->create()), ['phone' => '+380671234567']);
        $touch = $this->ingest(Channel::Viber, '+380999999999');

        $this->assertNull($touch->candidate_id);
        $this->assertNull($touch->application_id);
        $this->assertNull($application->fresh()?->last_touch_at);
        $this->assertSame(1, Touchpoint::query()->whereNull('candidate_id')->count());
        $this->assertNull($this->ingest(Channel::Telegram, null)->candidate_id);
    }

    public function test_dedupe_by_channel_and_external_id(): void
    {
        $first = $this->ingest(Channel::Whatsapp, '+380931112233', ['external_id' => 'wa-1']);
        $again = $this->ingest(Channel::Whatsapp, '+380931112233', ['external_id' => 'wa-1', 'body' => 'changed']);
        $otherChannel = $this->ingest(Channel::Viber, '+380931112233', ['external_id' => 'wa-1']);

        $this->assertSame($first->id, $again->id);
        $this->assertNotSame($first->id, $otherChannel->id);
        $this->assertSame(2, Touchpoint::query()->count());
        // No external id → never deduplicated.
        $this->ingest(Channel::Whatsapp, '+380931112233');
        $this->ingest(Channel::Whatsapp, '+380931112233');
        $this->assertSame(4, Touchpoint::query()->count());
    }

    public function test_candidate_without_applications_is_matched_without_application(): void
    {
        $candidate = Candidate::factory()->create(['email' => 'solo@example.test']);
        $touch = $this->ingest(Channel::Email, 'solo@example.test', ['at' => Carbon::parse('2026-09-01 10:00:00')]);

        $this->assertSame($candidate->id, $touch->candidate_id);
        $this->assertNull($touch->application_id);
    }
}
