<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Parsers;

use App\Modules\MailAgent\Enums\ParserKey;

/**
 * work.ua. ASSUMED subject "Новий відгук на вакансію «X»" — not confirmed on real mail; the common rules still apply.
 */
final class WorkUaParser extends AbstractMailParser
{
    public function key(): ParserKey
    {
        return ParserKey::WorkUa;
    }

    protected function subjectPatterns(): array
    {
        return ['/(?:новий|новый)\s+(?:відгук|отклик)[^«"“]*[«"“](?<v>[^»"”]+)[»"”]/iu'];
    }
}
