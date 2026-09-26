<?php

declare(strict_types=1);

namespace App\Modules\Channels\Http\Controllers;

use App\Models\User;
use App\Modules\Channels\Contracts\MessageSender;
use App\Modules\Channels\Enums\ChannelMode;
use App\Modules\Channels\Http\Requests\SendMessageRequest;
use App\Modules\Channels\Http\Requests\StartCallRequest;
use App\Modules\Channels\Services\CallService;
use App\Modules\Channels\Services\ChannelContext;
use App\Modules\Channels\Services\MessageService;
use App\Modules\Channels\Support\ChannelRegistry;
use App\Modules\GoogleWorkspace\Contracts\Mailer;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Enums\MailerState;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Http\Resources\TouchpointResource;
use App\Modules\Recruiting\Models\Candidate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Sending from the candidate card and the channel availability the card needs. */
final class ChannelMessageController
{
    public function __construct(
        private readonly MessageService $messages,
        private readonly CallService $calls,
    ) {}

    /** GET /channels — which channels can send / call now (any active user). */
    public function index(ChannelRegistry $registry, ChannelContext $context, Mailer $mailer): JsonResponse
    {
        // E-mail = the connected Gmail; reason "reconnect_to_send" when it is connected read-only.
        $mail = $mailer->state();
        $data = [[
            'key' => GoogleService::Gmail->integrationKey(),
            'channel' => Channel::Email->value,
            'mode' => $mail === MailerState::Ready ? ChannelMode::Live->value : ChannelMode::Off->value,
            'reason' => $mail === MailerState::Ready ? null : $mail->value,
        ]];
        foreach ($registry->all() as $adapter) {
            if ($adapter instanceof MessageSender || $adapter->channel() === Channel::Call) {
                $data[] = ['key' => $adapter->key(), 'channel' => $adapter->channel()->value, 'mode' => $context->mode($adapter)->value];
            }
        }

        return new JsonResponse(['data' => $data]);
    }

    public function send(SendMessageRequest $request, Candidate $candidate): JsonResponse
    {
        $touchpoint = $this->messages->send($this->actor($request), $candidate, $request->channel(), $request->text(), $request->applicationId(), $request->subject());

        return (new TouchpointResource($touchpoint))->response()->setStatusCode(201);
    }

    public function call(StartCallRequest $request, Candidate $candidate): JsonResponse
    {
        $key = $this->calls->start($this->actor($request), $candidate);

        return new JsonResponse(['data' => ['status' => 'requested', 'integration' => $key]], 202);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
