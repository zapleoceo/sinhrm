// npm run test:docs — security headers of the SPA (frontend/vercel.json): the Permissions-Policy opens only what the app uses.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const config = JSON.parse(readFileSync(new URL('../vercel.json', import.meta.url), 'utf8'));

function header(name) {
  const rule = config.headers.find((h) => h.source === '/((?!api/|sanctum/).*)');
  assert.ok(rule, 'SPA header rule exists');
  return rule.headers.find((h) => h.key === name)?.value;
}

test('Permissions-Policy: microphone only for the own origin (voice dictation), the rest stays off', () => {
  const directives = Object.fromEntries(
    header('Permissions-Policy')
      .split(',')
      .map((d) => d.trim().split('=')),
  );
  assert.equal(directives.microphone, '(self)');
  for (const off of ['camera', 'geolocation', 'payment', 'usb', 'interest-cohort']) {
    assert.equal(directives[off], '()', off);
  }
  assert.doesNotMatch(header('Permissions-Policy'), /\*/);
});

test('CSP keeps scripts strictly on the own origin', () => {
  assert.match(header('Content-Security-Policy'), /script-src 'self';/);
  assert.match(header('Content-Security-Policy'), /frame-ancestors 'none'/);
});

test('isolation headers: no framing, no cross-origin opener, HSTS (security audit 2026-10)', () => {
  assert.equal(header('X-Frame-Options'), 'DENY');
  assert.equal(header('X-Content-Type-Options'), 'nosniff');
  assert.equal(header('Cross-Origin-Opener-Policy'), 'same-origin');
  assert.match(header('Strict-Transport-Security'), /max-age=31536000/);
  assert.match(header('Content-Security-Policy'), /object-src 'none'/);
  assert.match(header('Content-Security-Policy'), /base-uri 'self'/);
});
