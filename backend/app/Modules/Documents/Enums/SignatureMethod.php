<?php

declare(strict_types=1);

namespace App\Modules\Documents\Enums;

enum SignatureMethod: string
{
    /** "Ознайомлений": the employee confirmed reading in SinHRM (simple acknowledgement, not an e-signature). */
    case ManualAck = 'manual_ack';
    /** Reserved: a qualified e-signature (Дія.Підпис / Вчасно). Not implemented — integration placeholder. */
    case KepPending = 'kep_pending';
}
