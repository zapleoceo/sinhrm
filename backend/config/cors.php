<?php

declare(strict_types=1);

// CORS is needed only by the browser extension (SinHRM Clipper): the SPA is same-origin with the API (Vercel
// rewrite). Only /api/clipper/* answers cross-origin requests, only from chrome-extension:// origins, and without
// credentials — the extension authenticates with its bearer token, never with the session cookie.
return [

    'paths' => ['api/clipper/*'],

    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],

    'allowed_origins' => [],

    // Chrome extension ids are 32 letters a–p.
    'allowed_origins_patterns' => ['#^chrome-extension://[a-p]{32}$#'],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type'],

    'exposed_headers' => ['Retry-After', 'X-RateLimit-Remaining'],

    'max_age' => 600,

    'supports_credentials' => false,

];
