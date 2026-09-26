<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Modules\Core\Contracts\ScheduledJob;
use Illuminate\Support\Carbon;

/**
 * "ai.screen" (cron every ~30 min): when the setting ai_screening_auto is on, submits AI screenings of new active
 * applications (last 48 h, without a screening), a few per run, without waiting; ai.poll stores the answers.
 */
final readonly class AutoScreeningJob implements ScheduledJob
{
    public function __construct(private ScreeningService $screenings) {}

    public function name(): string
    {
        return 'ai.screen';
    }

    public function run(Carbon $now): array
    {
        return $this->screenings->autoScreen($now);
    }
}
