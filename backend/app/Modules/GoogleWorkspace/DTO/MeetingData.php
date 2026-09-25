<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\DTO;

use Illuminate\Support\Carbon;

/** "Schedule a meeting" from the candidate card. type: branch (in person) | online (Google Meet link). */
final readonly class MeetingData
{
    public const string TYPE_BRANCH = 'branch';

    public const string TYPE_ONLINE = 'online';

    public const array TYPES = [self::TYPE_BRANCH, self::TYPE_ONLINE];

    public function __construct(
        public string $title,
        public Carbon $start,
        public int $durationMinutes,
        public string $type,
        public bool $inviteCandidate,
        public ?string $location = null,
        public ?string $notes = null,
    ) {}

    public function end(): Carbon
    {
        return $this->start->copy()->addMinutes($this->durationMinutes);
    }

    public function isOnline(): bool
    {
        return $this->type === self::TYPE_ONLINE;
    }
}
