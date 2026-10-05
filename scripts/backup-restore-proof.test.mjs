import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const script = new URL('./backup-restore-proof.sh', import.meta.url);
const workflow = readFileSync(new URL('../.github/workflows/backup-restore.yml', import.meta.url), 'utf8');
const shell = readFileSync(script, 'utf8');

test('CI uses ephemeral PostgreSQL 17 and a newly generated key without secrets or dump artifacts', () => {
  assert.match(workflow, /image: postgres:17/);
  assert.match(workflow, /php artisan key:generate/);
  assert.match(workflow, /contents: read/);
  assert.doesNotMatch(workflow, /secrets\.|upload-artifact|pull_request_target/);
  assert.match(shell, /trap 'rm -f -- "\$proof_dump"' EXIT/);
  assert.doesNotMatch(shell, /curl|wget|upload-artifact|pg_restore.*--clean/);
});

test('source is dumped with matching service client and removed before isolated restore and verification', () => {
  const checkpoints = ['php artisan migrate', 'RESTORE_PROOF_PHASE=seed', 'pg_dump -U app', 'createdb -U app app_dump_restored',
    'dropdb -U app app_dump_source', 'pg_restore -U app -d app_dump_restored', 'DB_DATABASE=app_dump_restored RESTORE_PROOF_PHASE=verify'];
  let previous = -1;
  for (const checkpoint of checkpoints) {
    const index = shell.indexOf(checkpoint);
    assert.ok(index > previous, `Missing/out-of-order phase: ${checkpoint}`);
    previous = index;
  }
  assert.match(shell, /pg_restore[^\n]*--exit-on-error[^\n]*--single-transaction/);
  assert.match(shell, /docker exec "\$POSTGRES_CONTAINER" pg_dump/);
  assert.match(shell, /docker exec -i "\$POSTGRES_CONTAINER" pg_restore/);
});

test('script rejects local execution and altered database/host/container before commands', { skip: process.platform === 'win32' }, () => {
  for (const override of [
    { GITHUB_ACTIONS: '' }, { DB_HOST: 'db.example.test' }, { DB_DATABASE: 'production' },
    { APP_ENV: 'production' }, { POSTGRES_CONTAINER: 'container;bad' }, { DB_URL: 'postgres://invalid.example.test/db' },
  ]) {
    const result = spawnSync('bash', [script.pathname], {
      encoding: 'utf8',
      env: { PATH: process.env.PATH, GITHUB_ACTIONS: 'true', DB_HOST: '127.0.0.1', DB_DATABASE: 'app_dump_source', APP_ENV: 'testing', POSTGRES_CONTAINER: 'a123', ...override },
    });
    assert.equal(result.status, 1);
    assert.doesNotMatch(result.stdout + result.stderr, /artisan|docker/);
  }
});
