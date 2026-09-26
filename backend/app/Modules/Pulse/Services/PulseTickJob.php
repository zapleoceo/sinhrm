<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Services;

use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Pulse\Contracts\SurveyRepository;
use Illuminate\Support\Carbon;

/**
 * "pulse.tick" for POST /api/ops/jobs/run (cron every ~30 min): opens scheduled waves, closes ended ones (wiping
 * their salt and creating the next wave of a recurring schedule), starts 30/90-day lifecycle surveys, raises mood
 * alerts for managers. Idempotent: every step is a state transition or a unique insert.
 */
final readonly class PulseTickJob implements ScheduledJob
{
    public function __construct(
        private SurveyRepository $surveys,
        private WaveLifecycle $lifecycle,
        private LifecycleSurveys $lifecycleSurveys,
        private MoodAlerts $alerts,
    ) {}

    public function name(): string
    {
        return 'pulse.tick';
    }

    public function run(Carbon $now): array
    {
        $opened = 0;
        foreach ($this->surveys->dueToOpen($now) as $wave) {
            $this->lifecycle->open($wave);
            $opened++;
        }
        $closed = 0;
        $scheduled = 0;
        foreach ($this->surveys->dueToClose($now) as $wave) {
            $scheduled += $this->lifecycle->close($wave, $now) ? 1 : 0;
            $closed++;
        }

        return [
            'waves_opened' => $opened,
            'waves_closed' => $closed,
            'waves_scheduled' => $scheduled,
            'lifecycle_started' => $this->lifecycleSurveys->hiresDue($now),
            'mood_alerts' => $this->alerts->run($now),
        ];
    }
}
