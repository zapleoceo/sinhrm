// npm run test:docs — security headers of the SPA server (server/headers.mjs): the Permissions-Policy opens only what the app uses.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { SPA_HEADERS, isBackendPath } from '../server/headers.mjs';

const header = (name) => SPA_HEADERS[name];

test('Permissions-Policy: microphone only for the own origin (voice dictation), the rest stays off', () => {
  const directives = Object.fromEntries(header('Permissions-Policy').split(',').map((d) => d.trim().split('=')));
  assert.equal(directives.microphone, '(self)');
  for (const off of ['camera', 'geolocation', 'payment', 'usb', 'interest-cohort']) assert.equal(directives[off], '()', off);
  assert.doesNotMatch(header('Permissions-Policy'), /\*/);
});

test('CSP keeps scripts strictly on the own origin', () => {
  assert.match(header('Content-Security-Policy'), /script-src 'self';/);
  assert.match(header('Content-Security-Policy'), /frame-ancestors 'none'/);
  assert.match(header('Content-Security-Policy'), /object-src 'none'/);
  assert.match(header('Content-Security-Policy'), /base-uri 'self'/);
});

test('isolation headers: no framing, no cross-origin opener, HSTS', () => {
  assert.equal(header('X-Frame-Options'), 'DENY');
  assert.equal(header('X-Content-Type-Options'), 'nosniff');
  assert.equal(header('Cross-Origin-Opener-Policy'), 'same-origin');
  assert.match(header('Strict-Transport-Security'), /max-age=31536000/);
});

test('isBackendPath: only /api subtree and /health belong to the backend', () => {
  for (const p of ['/api', '/api/me', '/api/sanctum/csrf-cookie', '/health']) assert.equal(isBackendPath(p), true, p);
  for (const p of ['/', '/apiary', '/docs/api', '/sanctum/csrf-cookie', '/healthz', '/recruiting/health']) assert.equal(isBackendPath(p), false, p);
});
