<?php

declare(strict_types=1);

namespace Tests\Feature\Perform;

use App\Modules\Auth\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/** Feedback visibility per setting, requests and answers (once). */
final class FeedbackTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    public function test_visibility_rules(): void
    {
        ['lead' => $lead, 'worker' => $worker, 'peer' => $peer, 'other' => $other] = $this->org();
        $from = $this->userOf($other);
        foreach (['private_to_recipient' => 'Private praise', 'manager' => 'For the manager too', 'public' => 'Kudos for everyone'] as $visibility => $text) {
            $this->actingAs($from)->postJson('/api/perform/feedback', [
                'to_employee_id' => $worker->id, 'type' => 'praise', 'text' => $text, 'visibility' => $visibility,
            ])->assertCreated()->assertJsonPath('data.from.id', $other->id);
        }

        $this->actingAs($this->userOf($worker))->getJson('/api/perform/feedback?box=received')->assertOk()->assertJsonCount(3, 'data');
        $this->actingAs($from)->getJson('/api/perform/feedback?box=given')->assertOk()->assertJsonCount(3, 'data');
        // The manager: manager + public, never the private one.
        $team = (string) $this->actingAs($this->userOf($lead))->getJson('/api/perform/feedback?box=team')->assertOk()->assertJsonCount(2, 'data')->getContent();
        $this->assertStringNotContainsString('Private praise', $team);
        // A colleague: public only.
        $public = (string) $this->actingAs($this->userOf($peer))->getJson('/api/perform/feedback?box=public')->assertOk()->assertJsonCount(1, 'data')->getContent();
        $this->assertStringContainsString('Kudos for everyone', $public);
        $this->actingAs($this->userOf($peer))->getJson('/api/perform/feedback?box=team')->assertOk()->assertJsonCount(0, 'data');
        // Admin: everything.
        $this->actingAs($this->login(UserRole::Admin))->getJson('/api/perform/feedback?box=team')->assertOk()->assertJsonCount(3, 'data');
        $this->actingAs($from)->getJson('/api/perform/feedback?box=nope')->assertUnprocessable();
    }

    public function test_request_and_answer_once(): void
    {
        ['worker' => $worker, 'peer' => $peer, 'other' => $other] = $this->org();
        $request = $this->actingAs($this->userOf($worker))->postJson('/api/perform/feedback', [
            'to_employee_id' => $peer->id, 'type' => 'request', 'text' => 'How did my demo go?', 'visibility' => 'public',
        ])->assertCreated()->assertJsonPath('data.visibility', 'private_to_recipient')->json('data.id');

        $this->actingAs($this->userOf($peer))->getJson('/api/perform/feedback?box=requests')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_answer', true);
        // Only the addressee answers.
        $this->actingAs($this->userOf($other))->postJson('/api/perform/feedback', ['request_id' => $request, 'type' => 'praise', 'text' => 'x'])
            ->assertConflict()->assertJsonPath('code', 'request_not_open');

        $this->actingAs($this->userOf($peer))->postJson('/api/perform/feedback', [
            'request_id' => $request, 'type' => 'constructive', 'text' => 'Slow down on slide 3', 'visibility' => 'private_to_recipient',
        ])->assertCreated()->assertJsonPath('data.to.id', $worker->id)->assertJsonPath('data.request_id', $request);
        $this->actingAs($this->userOf($peer))->postJson('/api/perform/feedback', ['request_id' => $request, 'type' => 'praise', 'text' => 'again'])
            ->assertConflict();
        $this->actingAs($this->userOf($peer))->getJson('/api/perform/feedback?box=requests')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->userOf($worker))->getJson('/api/perform/feedback?box=received')->assertOk()->assertJsonPath('data.0.text', 'Slow down on slide 3');
    }

    public function test_no_self_feedback_and_needs_an_employee(): void
    {
        ['worker' => $worker] = $this->org();
        $this->actingAs($this->userOf($worker))->postJson('/api/perform/feedback', ['to_employee_id' => $worker->id, 'type' => 'praise', 'text' => 'Me'])
            ->assertUnprocessable()->assertJsonPath('code', 'self_target');
        $this->actingAs($this->login(UserRole::Admin))->postJson('/api/perform/feedback', ['to_employee_id' => $worker->id, 'type' => 'praise', 'text' => 'Hi'])
            ->assertUnprocessable()->assertJsonPath('code', 'no_employee');
    }
}
