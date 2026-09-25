<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Contracts;

use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;

/** A valid access token of a connected service (cached, refreshed on demand). */
interface GoogleTokenProvider
{
    /**
     * May refresh and store a new token (side effects).
     *
     * @phpstan-impure
     *
     * @throws GoogleException not connected / reconnect_required / network
     */
    public function accessToken(GoogleService $service): string;

    /** Forget the cached token (after a 401), so the next accessToken() refreshes. */
    public function invalidate(GoogleService $service): void;
}
