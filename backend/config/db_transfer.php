<?php

declare(strict_types=1);

/*
 * PostgreSQL (Neon) -> MySQL 8.4 data transfer: `php artisan db:transfer-to-mysql` (ADR 0010, PROD-47,
 * docs/guides/mysql-cutover.md). Connection strings come ONLY from the environment, never from command-line
 * arguments, and are never printed. The target connection reuses the `mysql` connection settings (utf8mb4,
 * utf8mb4_0900_ai_ci, strict sql_mode, time_zone +00:00), so the copy runs with the same session as the app.
 */
return [
    'source_url' => env('TRANSFER_SOURCE_URL'),
    'target_url' => env('TRANSFER_TARGET_URL'),
    // Rows per read/insert batch; inserts are additionally split to stay well under max_allowed_packet.
    'chunk' => (int) env('TRANSFER_CHUNK', 500),
];
