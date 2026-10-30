// Security headers of the SPA. Applied by server.mjs to every non-API response (nginx terminates TLS in front, hence HSTS).
// Tested by scripts/server-headers.test.mjs.
export const SPA_HEADERS = {
  'Content-Security-Policy':
    "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https://*.googleusercontent.com; manifest-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; upgrade-insecure-requests",
  'X-Frame-Options': 'DENY',
  'X-Content-Type-Options': 'nosniff',
  'Referrer-Policy': 'strict-origin-when-cross-origin',
  'Permissions-Policy': 'camera=(), microphone=(self), geolocation=(), payment=(), usb=(), interest-cohort=()',
  'Cross-Origin-Opener-Policy': 'same-origin',
  'Strict-Transport-Security': 'max-age=31536000; includeSubDomains',
};

/** Paths owned by the backend (nginx routes them there): never served from the SPA, so a misrouted one is a 404, not index.html. */
export const isBackendPath = (pathname) => pathname === '/api' || pathname.startsWith('/api/') || pathname === '/health';
