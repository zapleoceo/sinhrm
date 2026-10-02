<?php

declare(strict_types=1);

return [
    // Proxies whose X-Forwarded-For is believed (read by Laravel's global TrustProxies middleware on every request).
    // Comma-separated IPs/CIDRs, e.g. "127.0.0.1,::1". Not set → nobody is trusted and $request->ip() is REMOTE_ADDR,
    // so a client cannot pick its own IP with a header. Never "*": that would trust a header any client can send.
    // On Vercel (vercel-php) the PHP built-in server is called by the runtime's own Node launcher over loopback, so
    // REMOTE_ADDR is always 127.0.0.1 and the real client is in X-Forwarded-For, which the Vercel edge overwrites with
    // the client's public IP (client-sent values are not forwarded). Only the X-Forwarded-For header is trusted
    // (bootstrap/app.php): Host / Proto / Port / Prefix are not taken from headers. See docs/modules/core.md.
    'proxies' => env('TRUSTED_PROXIES'),
];
