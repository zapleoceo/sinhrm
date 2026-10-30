// npm run test:docs — static lookup of server.mjs (server/static.mjs): traversal, fallback decisions, cache policy.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, realpathSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { cacheControl, resolveFile } from '../server/static.mjs';

const root = realpathSync(mkdtempSync(join(tmpdir(), 'sinhrm-static-')));
writeFileSync(join(root, 'index.html'), '<html></html>');
mkdirSync(join(root, 'media'));
writeFileSync(join(root, 'media', 'a.png'), 'x');

test('resolveFile finds files, rejects directories, missing files and traversal', async () => {
  assert.equal(await resolveFile(root, '/index.html'), join(root, 'index.html'));
  assert.equal(await resolveFile(root, '/media/a.png'), join(root, 'media', 'a.png'));
  assert.equal(await resolveFile(root, '/media'), null);
  assert.equal(await resolveFile(root, '/nope.js'), null);
  assert.equal(await resolveFile(root, '/../../etc/passwd'), null);
  assert.equal(await resolveFile(root, '/%2e%2e/%2e%2e/etc/passwd'), null);
  assert.equal(await resolveFile(root, '/%00'), null);
  assert.equal(await resolveFile(root, '/%E0%A4%A'), null);
});

test('cacheControl: hashed bundles immutable, html and build.json never cached long', () => {
  assert.match(cacheControl('/x/main-ABCD1234.js', '/main-ABCD1234.js'), /immutable/);
  assert.equal(cacheControl('/x/index.html', '/index.html'), 'no-cache');
  assert.equal(cacheControl('/x/build.json', '/build.json'), 'no-store');
});
