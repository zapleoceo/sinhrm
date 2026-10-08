import assert from 'node:assert/strict';
import test from 'node:test';
import { findings, isAllowed } from './mysql-only-guard.mjs';

// Forbidden words are assembled from parts so this file passes the guard itself.
const DB = 'Post' + 'greSQL';
const DRIVER = 'pg' + 'sql';
const OP = 'IL' + 'IKE';
const HOST = 'Ne' + 'on';

test('flags the former database, its driver, hosting and LIKE operator with path and line', () => {
  const content = ['ok', `stored in ${DB}`, `DB_CONNECTION=${DRIVER}`, `where name ${OP} ?`, `${HOST} (Frankfurt)`].join('\n');
  const out = findings('backend/app/Modules/People/Foo.php', content);
  assert.deepEqual(out.map((f) => f.split(': ')[0]), [2, 3, 4, 5].map((n) => `backend/app/Modules/People/Foo.php:${n}`));
});

test('does not flag look-alikes: OneOnOne, .neon config files, MySQL wording', () => {
  const content = ['OneOnOneService', 'phpstan.' + 'ne' + 'on', '"extension.' + 'ne' + 'on"', 'MySQL 8.4, utf8mb4_0900_ai_ci', 'whereLike'].join('\n');
  assert.deepEqual(findings('backend/app/Modules/Perform/OneOnOne.php', content), []);
});

test('only the cutover tool, its runbook and history are exempt', () => {
  for (const p of ['backend/app/Modules/Core/Transfer/TransferDatabases.php', 'backend/tests/Unit/Core/Transfer/X.php',
    'backend/tests/Feature/Core/Transfer/X.php', '.github/workflows/mysql-data-transfer.yml', 'docs/guides/mysql-cutover.md',
    'docs/adr/0011-mysql-only.md', 'docs/worklog.d/2026-10-08-x.md', 'docs/worklog.md']) {
    assert.ok(isAllowed(p), p);
  }
  for (const p of ['backend/app/Modules/Core/TransferX.php', 'backend/app/Modules/Core/Services/Demo/DemoDataService.php',
    'docs/guides/deploy.md', 'backend/vercel.json', 'docs/adr', 'scripts/mysql-only-guard.mjs']) {
    assert.ok(!isAllowed(p), p);
  }
  assert.deepEqual(findings('docs/adr/0010-mysql-dual-support.md', DB), []);
});

test('binary content is skipped', () => {
  assert.deepEqual(findings('frontend/public/x.png', `\u0000${DB}`), []);
});
