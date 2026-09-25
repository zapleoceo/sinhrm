<?php

declare(strict_types=1);

return [
    // Shared secret for machine-only endpoints (/api/ops/*) called by GitHub Actions (deploy, cron).
    // Header: X-Ops-Secret. Not set → the endpoints answer 404 as if they did not exist.
    'secret' => env('OPS_SECRET'),
];
