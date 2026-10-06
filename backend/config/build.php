<?php

declare(strict_types=1);

$path = base_path('build.json');
$metadata = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

return ['sha' => is_array($metadata) ? ($metadata['sha'] ?? null) : null];
