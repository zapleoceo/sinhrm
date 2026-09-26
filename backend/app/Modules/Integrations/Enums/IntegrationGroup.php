<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Enums;

enum IntegrationGroup: string
{
    case Ai = 'ai';
    case Google = 'google';
    case Messengers = 'messengers';
    case Telephony = 'telephony';
    case Sources = 'sources';
    /** Document signing (qualified e-signature). */
    case Documents = 'documents';
}
