<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use App\Modules\Core\DTO\DataSubject;
use Illuminate\Support\Carbon;

/**
 * Subjects whose data may be anonymized automatically by the retention rule (Privacy settings, off by default).
 * Recruiting: candidates whose every application is rejected and closed before $before, not anonymized yet.
 * Modules register sources with $this->app->tag([...], RetentionSource::class).
 */
interface RetentionSource
{
    /** @return list<DataSubject> */
    public function expired(Carbon $before, int $limit): array;
}
