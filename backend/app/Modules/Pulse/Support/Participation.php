<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Support;

/**
 * Coarse participation of an open wave (pure). While a wave is open only this is shown: a range of answer counts
 * ("0–4", "5–9", "10–19", "20+") and the share of the audience rounded to 10 % — one more answer rarely changes
 * either, and no score or text is ever shown before the wave is closed.
 */
final class Participation
{
    /** @return array{responded_bucket: string, responded_percent: int|null} */
    public static function of(int $responses, int $audience): array
    {
        return [
            'responded_bucket' => self::bucket($responses),
            'responded_percent' => $audience > 0 ? (int) (round(min(1, $responses / $audience) * 10) * 10) : null,
        ];
    }

    public static function bucket(int $count): string
    {
        return match (true) {
            $count < 5 => '0–4',
            $count < 10 => '5–9',
            $count < 20 => '10–19',
            default => '20+',
        };
    }
}
