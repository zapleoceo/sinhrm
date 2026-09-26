<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Services;

use App\Modules\Core\Contracts\RetentionSource;
use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Privacy\Exceptions\PrivacyException;
use App\Modules\Privacy\Models\PrivacySettings;
use Illuminate\Support\Carbon;

/**
 * "privacy.retention" (cron every ~30 min): when the retention rule is on (N months), anonymizes subjects the
 * sources report as expired — Recruiting: candidates whose every application was rejected and closed more than
 * N months ago. A batch per run; blocked subjects (e.g. hired) are skipped.
 */
final readonly class RetentionJob implements ScheduledJob
{
    public const int BATCH = 50;

    /** @param  iterable<RetentionSource>  $sources */
    public function __construct(private iterable $sources, private PersonalDataService $service) {}

    public function name(): string
    {
        return 'privacy.retention';
    }

    public function run(Carbon $now): array
    {
        $months = PrivacySettings::current()->retention_rejected_months;
        if ($months === null || $months < 1) {
            return ['enabled' => false];
        }
        $before = $now->copy()->subMonthsNoOverflow($months);
        $erased = 0;
        $skipped = 0;
        foreach ($this->sources as $source) {
            foreach ($source->expired($before, self::BATCH) as $subject) {
                try {
                    $this->service->erase($subject, 'retention: '.$months.' months', null, 'retention');
                    $erased++;
                } catch (PrivacyException) {
                    $skipped++;
                }
            }
        }

        return ['enabled' => true, 'erased' => $erased, 'skipped' => $skipped];
    }
}
