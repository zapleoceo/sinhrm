<?php

declare(strict_types=1);

namespace App\Modules\Channels\Contracts;

use App\Modules\Channels\DTO\OutgoingMessage;
use App\Modules\Channels\DTO\SentMessage;
use App\Modules\Channels\Exceptions\ChannelException;
use App\Modules\Integrations\DTO\IntegrationConfig;

/** A channel that can send a text message (from the candidate card, or a superadmin test). */
interface MessageSender
{
    /** @throws ChannelException no_conversation | template_required | invalid_recipient | send_failed */
    public function send(OutgoingMessage $message, IntegrationConfig $config): SentMessage;

    /**
     * Test message to a raw recipient typed by the superadmin (chat id / phone / Viber user id).
     *
     * @throws ChannelException
     */
    public function sendTest(string $to, string $text, IntegrationConfig $config): SentMessage;
}
