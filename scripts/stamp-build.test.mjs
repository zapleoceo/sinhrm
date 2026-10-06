import { execFileSync } from 'node:child_process';
import { readFileSync, rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';
import test from 'node:test';

const root = fileURLToPath(new URL('../', import.meta.url));
test('stamps actual checkout instead of workflow_run runner/main SHA', () => {
  const expected = execFileSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim();
  const paths = ['backend/build.json', 'frontend/public/build.json'];
  try {
    execFileSync(process.execPath, ['scripts/stamp-build.mjs'], {
      cwd: root, env: { ...process.env, GITHUB_SHA: 'b'.repeat(40), VERCEL_GIT_COMMIT_SHA: 'c'.repeat(40) },
    });
    for (const path of paths) {
      assert.deepEqual(JSON.parse(readFileSync(new URL(`../${path}`, import.meta.url), 'utf8')), { sha: expected });
    }
  } finally {
    for (const path of paths) rmSync(new URL(`../${path}`, import.meta.url), { force: true });
  }
});

test('deploy stamps workflow_run checkout before either build and publishes Web asset', () => {
  const workflow = readFileSync(new URL('../.github/workflows/deploy.yml', import.meta.url), 'utf8');
  const deploy = workflow.slice(workflow.indexOf('\n  deploy:'));
  assert.ok(deploy.indexOf('github.event.workflow_run.head_sha') < deploy.indexOf('node scripts/stamp-build.mjs'));
  assert.ok(deploy.indexOf('node scripts/stamp-build.mjs') < deploy.indexOf('vercel build'));
  const angular = JSON.parse(readFileSync(new URL('../frontend/angular.json', import.meta.url), 'utf8'));
  assert.ok(angular.projects.frontend.architect.build.options.assets.some(a => a.input === 'public' && a.glob === '**/*'));
  const vercel = JSON.parse(readFileSync(new URL('../frontend/vercel.json', import.meta.url), 'utf8'));
  const fallback = vercel.rewrites.find(r => r.destination === '/index.html');
  assert.equal(new RegExp(`^${fallback.source}$`).test('/build.json'), false);
  assert.equal(new RegExp(`^${fallback.source}$`).test('/candidates'), true);
  assert.equal(vercel.headers.find(h => h.source === '/build.json').headers[0].value, 'no-store');
});
