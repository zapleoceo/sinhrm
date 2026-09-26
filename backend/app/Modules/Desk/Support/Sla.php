<?php

declare(strict_types=1);

namespace App\Modules\Desk\Support;

use Illuminate\Support\Carbon;

/**
 * SLA of one case (pure): due times from the opening time plus the category's hours, and breach flags.
 * A target is breached when the event happened after the due time — or has not happened yet and the due time passed.
 * Resolving (or closing) stops the resolve clock; the first public reply of HR stops the first-response clock.
 */
final class Sla
{
    /**
     * @return array{first_response_due: Carbon|null, resolve_due: Carbon|null, first_response_breached: bool, resolve_breached: bool}
     */
    public static function of(
        Carbon $openedAt,
        ?int $firstResponseHours,
        ?int $resolveHours,
        ?Carbon $firstResponseAt,
        ?Carbon $resolvedAt,
        Carbon $now,
    ): array {
        $firstDue = $firstResponseHours === null ? null : $openedAt->copy()->addHours($firstResponseHours);
        $resolveDue = $resolveHours === null ? null : $openedAt->copy()->addHours($resolveHours);

        return [
            'first_response_due' => $firstDue,
            'resolve_due' => $resolveDue,
            'first_response_breached' => self::breached($firstDue, $firstResponseAt, $now),
            'resolve_breached' => self::breached($resolveDue, $resolvedAt, $now),
        ];
    }

    private static function breached(?Carbon $due, ?Carbon $happenedAt, Carbon $now): bool
    {
        return $due !== null && ($happenedAt ?? $now)->gt($due);
    }
}
