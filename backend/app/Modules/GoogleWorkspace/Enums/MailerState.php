<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Enums;

/** Can the company mailbox send? not_connected — Gmail not connected; reconnect_to_send — connected read-only. */
enum MailerState: string
{
    case Ready = 'ready';
    case NotConnected = 'not_connected';
    case ReconnectToSend = 'reconnect_to_send';
}
