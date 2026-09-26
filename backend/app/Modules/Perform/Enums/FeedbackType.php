<?php

declare(strict_types=1);

namespace App\Modules\Perform\Enums;

/** Continuous feedback kind; a request asks the recipient to give feedback back. */
enum FeedbackType: string
{
    case Praise = 'praise';
    case Constructive = 'constructive';
    case Request = 'request';
}
