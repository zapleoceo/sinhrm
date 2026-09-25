<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Enums;

use App\Modules\Recruiting\Enums\CandidateSource;

/** Parser of job-board mail (sender_rules.parser). Formats are NOT confirmed on real mail — see docs/modules/mail-agent.md. */
enum ParserKey: string
{
    case WorkUa = 'work_ua';
    case RobotaUa = 'robota_ua';
    case Djinni = 'djinni';
    case Generic = 'generic';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }

    public function source(): CandidateSource
    {
        return match ($this) {
            self::WorkUa => CandidateSource::WorkUa,
            self::RobotaUa => CandidateSource::RobotaUa,
            self::Djinni => CandidateSource::Djinni,
            self::Generic => CandidateSource::Other,
        };
    }
}
