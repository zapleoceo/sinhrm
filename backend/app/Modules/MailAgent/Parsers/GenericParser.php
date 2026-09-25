<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Parsers;

use App\Modules\MailAgent\Enums\ParserKey;

/** Any job-board / careers-form mail: only the common tolerant rules of AbstractMailParser. */
final class GenericParser extends AbstractMailParser
{
    public function key(): ParserKey
    {
        return ParserKey::Generic;
    }
}
