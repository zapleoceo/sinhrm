<?php

declare(strict_types=1);

namespace App\Modules\Channels\DTO;

use Illuminate\Support\Carbon;

/**
 * Input of a demo event: contact of the sender (phone or @username, may be null), text, time and a unique id so a
 * simulated event never collides with another one.
 */
final readonly class DemoSeed
{
    public function __construct(
        public ?string $phone,
        public ?string $telegramUsername,
        public string $senderName,
        public string $text,
        public Carbon $at,
        public string $uid,
        public int $numericId,
    ) {}
}
