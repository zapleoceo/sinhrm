import assert from 'node:assert/strict';
import test from 'node:test';
import { findings, isSkipped } from './mysql-only-guard.mjs';

// Forbidden words are assembled from parts so this file passes the guard (and a plain grep) itself.
const DB = 'Post' + 'greSQL';
const DB_SHORT = 'post' + 'gres';
const DRIVER = 'pg' + 'sql';
const OP = 'IL' + 'IKE';
const HOST = 'Ne' + 'on';
const JSON_B = 'json' + 'b';
const SEQ = 'next' + 'val';
const CATALOG = 'p' + 'g_dump';
const CAST = '::' + 'text';
const FILE = 'backend/app/Modules/People/Foo.php';

const lines = (out) => out.map((f) => Number(f.split(': ')[0].split(':').pop()));

test('flags the database, its driver, hosting, LIKE operator, binary JSON, sequences, catalog tools and casts', () => {
  const content = ['ok', `stored in ${DB}`, `DB_CONNECTION=${DRIVER}`, `where name ${OP} ?`, `${HOST} (Frankfurt)`,
    `$table->${JSON_B}('meta')`, `select ${SEQ}('seq')`, `${CATALOG} --format=custom`, `where id${CAST} = ?`,
    `image: ${DB_SHORT}:17`].join('\n');
  assert.deepEqual(lines(findings(FILE, content)), [2, 3, 4, 5, 6, 7, 8, 9, 10]);
  assert.match(findings(FILE, `x ${DB}`)[0], /^backend\/app\/Modules\/People\/Foo\.php:1: x /);
});

test('does not flag look-alikes: OneOnOne, SceneOnly, .neon config files, static ::text( calls, enum ::Text, MySQL wording', () => {
  const content = ['OneOnOneService', 'SceneOnlyAction', 'phpstan.' + 'ne' + 'on', '"extension.' + 'ne' + 'on"',
    "FieldSpec::text('domain')", 'JsonOutput::text ($json, "x")', 'QuestionType::Text', 'MySQL 8.4, utf8mb4_0900_ai_ci',
    'whereLike', 'image.jpg_small', 'json()', 'jsonText'].join('\n');
  assert.deepEqual(findings('backend/app/Modules/Perform/OneOnOne.php', content), []);
});

test('no path is exempt: docs, history, workflows and the guard itself are checked; only lock files and vendored code are skipped', () => {
  for (const p of ['docs/adr/0010-mysql.md', 'docs/worklog.md', 'docs/worklog.d/2026-10-08-x.md', '.github/workflows/ci.yml',
    'docs/guides/backup-restore.md', 'scripts/mysql-only-guard.mjs', 'backend/app/Modules/Core/Transfer/X.php']) {
    assert.ok(!isSkipped(p), p);
    assert.equal(findings(p, DB).length, 1, p);
  }
  for (const p of ['frontend/package-lock.json', 'backend/composer.lock', 'backend/vendor/x/y.php', 'frontend/node_modules/a/b.js']) {
    assert.ok(isSkipped(p), p);
  }
});

test('binary content is skipped', () => {
  assert.deepEqual(findings('frontend/public/x.png', `\u0000${DB}`), []);
});
