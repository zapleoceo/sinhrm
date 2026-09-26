<?php

declare(strict_types=1);

namespace App\Modules\Perform\Enums;

/** Who may read feedback besides its author and recipient (admins always). */
enum FeedbackVisibility: string
{
    case PrivateToRecipient = 'private_to_recipient';
    case Manager = 'manager';
    case Public = 'public';
}
