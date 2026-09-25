<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Parsers;

use App\Modules\MailAgent\Enums\ParserKey;

/**
 * robota.ua. ASSUMED subject "Нове резюме на вакансію X" — not confirmed on real mail; the common rules still apply.
 */
final class RobotaUaParser extends AbstractMailParser
{
    public function key(): ParserKey
    {
        return ParserKey::RobotaUa;
    }

    protected function subjectPatterns(): array
    {
        return ['/(?:нове|новое)\s+резюме\s+на\s+(?:вакансію|вакансию)\s*[«"“]?(?<v>[^»"”]+?)[»"”]?\s*$/iu'];
    }
}
