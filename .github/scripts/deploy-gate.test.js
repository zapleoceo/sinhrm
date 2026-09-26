'use strict';

// Deterministic unit tests for deploy-gate.js — no test framework, just asserts that throw.
// Run with: node .github/scripts/deploy-gate.test.js

const assert = require('node:assert/strict');
const {
  classifyPaths,
  applyPreviewForce,
  findLastSuccessfulProductionSha,
  getChangedPathsForPush,
  getChangedPathsForPr,
  computeFlags,
} = require('./deploy-gate.js');

let passed = 0;
const pending = [];
function test(name, fn) {
  pending.push(async () => {
    await fn();
    passed++;
    console.log(`ok - ${name}`);
  });
}

// --- classifyPaths -----------------------------------------------------

test('backend change -> api only', () => {
  const flags = classifyPaths(['backend/app/Http/Controllers/FooController.php']);
  assert.deepEqual(flags, { api: true, web: false });
});

test('frontend change -> web only', () => {
  const flags = classifyPaths(['frontend/src/app/app.component.ts']);
  assert.deepEqual(flags, { api: false, web: true });
});

test('docs change -> web only (bundled into SPA help page)', () => {
  const flags = classifyPaths(['docs/guides/deploy.md']);
  assert.deepEqual(flags, { api: false, web: true });
});

test('workflow file change -> both', () => {
  const flags = classifyPaths(['.github/workflows/deploy.yml']);
  assert.deepEqual(flags, { api: true, web: true });
});

test('unrelated paths -> neither', () => {
  const flags = classifyPaths(['extension/manifest.json', 'README.md', 'rest/foo.http']);
  assert.deepEqual(flags, { api: false, web: false });
});

test('mixed backend + frontend -> both', () => {
  const flags = classifyPaths(['backend/routes/api.php', 'frontend/src/main.ts']);
  assert.deepEqual(flags, { api: true, web: true });
});

test('empty change set -> neither', () => {
  const flags = classifyPaths([]);
  assert.deepEqual(flags, { api: false, web: false });
});

// --- applyPreviewForce ---------------------------------------------------

test('production: api/web stay independent (web true, api untouched)', () => {
  const flags = applyPreviewForce({ api: false, web: true }, false);
  assert.deepEqual(flags, { api: false, web: true });
});

test('preview: web true forces api true', () => {
  const flags = applyPreviewForce({ api: false, web: true }, true);
  assert.deepEqual(flags, { api: true, web: true });
});

test('preview: api-only change stays api-only (web not forced)', () => {
  const flags = applyPreviewForce({ api: true, web: false }, true);
  assert.deepEqual(flags, { api: true, web: false });
});

test('preview: neither changed stays neither', () => {
  const flags = applyPreviewForce({ api: false, web: false }, true);
  assert.deepEqual(flags, { api: false, web: false });
});

// --- findLastSuccessfulProductionSha (mocked octokit) --------------------

function makeGithub({ pages, jobsByRunId }) {
  return {
    rest: {
      actions: {
        listWorkflowRuns: async ({ page }) => ({ data: { workflow_runs: pages[page - 1] || [] } }),
        listJobsForWorkflowRun: async ({ run_id }) => ({ data: { jobs: jobsByRunId[run_id] || [] } }),
      },
    },
  };
}

test('finds the most recent run whose deploy job succeeded', async () => {
  const github = makeGithub({
    pages: [
      [
        { id: 3, head_sha: 'sha-3' }, // deploy job failed on this one
        { id: 2, head_sha: 'sha-2' }, // deploy job succeeded
        { id: 1, head_sha: 'sha-1' },
      ],
    ],
    jobsByRunId: {
      3: [{ name: 'gate', conclusion: 'success' }, { name: 'deploy', conclusion: 'failure' }],
      2: [{ name: 'gate', conclusion: 'success' }, { name: 'deploy', conclusion: 'success' }],
      1: [{ name: 'gate', conclusion: 'success' }, { name: 'deploy', conclusion: 'success' }],
    },
  });
  const sha = await findLastSuccessfulProductionSha(github, { owner: 'o', repo: 'r' });
  assert.equal(sha, 'sha-2');
});

test('paginates across multiple pages until a success is found', async () => {
  const fullPage = Array.from({ length: 3 }, (_, i) => ({ id: 100 + i, head_sha: `p1-${i}` }));
  const github = makeGithub({
    pages: [
      fullPage, // page 1: none succeeded
      [{ id: 200, head_sha: 'sha-200' }], // page 2: found it
    ],
    jobsByRunId: {
      100: [{ name: 'deploy', conclusion: 'failure' }],
      101: [{ name: 'deploy', conclusion: 'failure' }],
      102: [{ name: 'deploy', conclusion: 'cancelled' }],
      200: [{ name: 'deploy', conclusion: 'success' }],
    },
  });
  const sha = await findLastSuccessfulProductionSha(github, { owner: 'o', repo: 'r' }, { perPage: 3 });
  assert.equal(sha, 'sha-200');
});

test('returns null when no run has a successful deploy job (first ever run case)', async () => {
  const github = makeGithub({
    pages: [[{ id: 1, head_sha: 'sha-1' }]],
    jobsByRunId: { 1: [{ name: 'deploy', conclusion: 'failure' }] },
  });
  const sha = await findLastSuccessfulProductionSha(github, { owner: 'o', repo: 'r' });
  assert.equal(sha, null);
});

test('returns null when there are no workflow runs at all', async () => {
  const github = makeGithub({ pages: [[]], jobsByRunId: {} });
  const sha = await findLastSuccessfulProductionSha(github, { owner: 'o', repo: 'r' });
  assert.equal(sha, null);
});

// --- getChangedPathsForPush / getChangedPathsForPr (mocked octokit) ------

function makeCompareGithub(expectedBasehead, files) {
  return {
    rest: {
      repos: {
        compareCommitsWithBasehead: async ({ basehead }) => {
          assert.equal(basehead, expectedBasehead);
          return {
            data: { status: 'ahead', merge_base_commit: { sha: 'mb' }, files: files.map((filename) => ({ filename })) },
          };
        },
      },
    },
  };
}

test('getChangedPathsForPush builds base...head and returns filenames', async () => {
  const github = makeCompareGithub('sha-old...sha-new', ['backend/foo.php', 'frontend/bar.ts']);
  const files = await getChangedPathsForPush(github, { owner: 'o', repo: 'r' }, 'sha-old', 'sha-new');
  assert.deepEqual(files, ['backend/foo.php', 'frontend/bar.ts']);
});

test('getChangedPathsForPr diffs from the PR base branch to the head sha', async () => {
  const github = makeCompareGithub('main...sha-pr-head', ['docs/guides/deploy.md']);
  const files = await getChangedPathsForPr(github, { owner: 'o', repo: 'r' }, 'main', 'sha-pr-head');
  assert.deepEqual(files, ['docs/guides/deploy.md']);
});

// --- computeFlags: fail-safe fallbacks -> deploy both ---------------------

const PUSH_RUN = { event: 'push', head_branch: 'main', head_sha: 'sha-new', pull_requests: [] };
const PR_RUN = {
  event: 'pull_request',
  head_branch: 'feat/x',
  head_sha: 'sha-pr',
  pull_requests: [{ number: 7, base: { ref: 'main' } }],
};
const REPO = { owner: 'o', repo: 'r' };
const lastDeployOk = {
  listWorkflowRuns: async () => ({ data: { workflow_runs: [{ id: 1, head_sha: 'sha-old' }] } }),
  listJobsForWorkflowRun: async () => ({ data: { jobs: [{ name: 'deploy', conclusion: 'success' }] } }),
};
function compareGithub(compare) {
  return { rest: { actions: lastDeployOk, repos: { compareCommitsWithBasehead: compare } } };
}
function compareOk(files, extra = {}) {
  return async () => ({
    data: { status: 'ahead', merge_base_commit: { sha: 'mb' }, files: files.map((filename) => ({ filename })), ...extra },
  });
}
function httpError(status, message) {
  const e = new Error(message);
  e.status = status;
  return e;
}
function collect() {
  const msgs = [];
  return { msgs, warn: (m) => msgs.push(m) };
}
async function flagsFor(github, run, w) {
  return computeFlags({ github, repo: REPO, run, warn: w.warn });
}
const BOTH = { api: true, web: true };

test('push: normal backend-only diff -> api only, no warning', async () => {
  const w = collect();
  assert.deepEqual(await flagsFor(compareGithub(compareOk(['backend/a.php'])), PUSH_RUN, w), { api: true, web: false });
  assert.equal(w.msgs.length, 0);
});

test('push: thrown error (rate limit 403) -> both + warning', async () => {
  const w = collect();
  const gh = compareGithub(async () => { throw httpError(403, 'API rate limit exceeded'); });
  assert.deepEqual(await flagsFor(gh, PUSH_RUN, w), BOTH);
  assert.match(w.msgs[0], /HTTP 403/);
});

test('push: error while listing workflow runs -> both', async () => {
  const w = collect();
  const gh = { rest: { actions: { listWorkflowRuns: async () => { throw new Error('boom'); } } } };
  assert.deepEqual(await flagsFor(gh, PUSH_RUN, w), BOTH);
  assert.equal(w.msgs.length, 1);
});

test('push: compare 404 (base gone after force-push) -> both', async () => {
  const w = collect();
  const gh = compareGithub(async () => { throw httpError(404, 'Not Found'); });
  assert.deepEqual(await flagsFor(gh, PUSH_RUN, w), BOTH);
  assert.match(w.msgs[0], /HTTP 404/);
});

test('push: compare status diverged -> both', async () => {
  const w = collect();
  assert.deepEqual(await flagsFor(compareGithub(compareOk(['README.md'], { status: 'diverged' })), PUSH_RUN, w), BOTH);
  assert.equal(w.msgs.length, 1);
});

test('push: compare status behind -> both', async () => {
  const w = collect();
  assert.deepEqual(await flagsFor(compareGithub(compareOk([], { status: 'behind' })), PUSH_RUN, w), BOTH);
});

test('push: missing merge base -> both', async () => {
  const w = collect();
  assert.deepEqual(await flagsFor(compareGithub(compareOk(['README.md'], { merge_base_commit: null })), PUSH_RUN, w), BOTH);
});

test('push: 300 files (compare cap, list may be truncated) -> both', async () => {
  const w = collect();
  const many = Array.from({ length: 300 }, (_, i) => `README-${i}.md`);
  assert.deepEqual(await flagsFor(compareGithub(compareOk(many)), PUSH_RUN, w), BOTH);
});

test('push: 299 unrelated files -> neither (cap boundary)', async () => {
  const w = collect();
  const many = Array.from({ length: 299 }, (_, i) => `README-${i}.md`);
  assert.deepEqual(await flagsFor(compareGithub(compareOk(many)), PUSH_RUN, w), { api: false, web: false });
});

test('push: no previous successful deploy -> both', async () => {
  const w = collect();
  const gh = { rest: { actions: { listWorkflowRuns: async () => ({ data: { workflow_runs: [] } }) } } };
  assert.deepEqual(await flagsFor(gh, PUSH_RUN, w), BOTH);
});

test('PR: thrown error -> both', async () => {
  const w = collect();
  const gh = compareGithub(async () => { throw httpError(500, 'Server Error'); });
  assert.deepEqual(await flagsFor(gh, PR_RUN, w), BOTH);
});

test('PR: compare 404 -> both', async () => {
  const w = collect();
  const gh = compareGithub(async () => { throw httpError(404, 'Not Found'); });
  assert.deepEqual(await flagsFor(gh, PR_RUN, w), BOTH);
});

test('PR: 300 files -> both', async () => {
  const w = collect();
  const many = Array.from({ length: 300 }, (_, i) => `extension/f${i}.ts`);
  assert.deepEqual(await flagsFor(compareGithub(compareOk(many)), PR_RUN, w), BOTH);
});

test('PR: diverged from main is normal -> classify from merge-base (web forces api)', async () => {
  const w = collect();
  const gh = compareGithub(compareOk(['frontend/a.ts'], { status: 'diverged' }));
  assert.deepEqual(await flagsFor(gh, PR_RUN, w), BOTH);
  assert.equal(w.msgs.length, 0);
});

test('PR: diverged, extension-only -> neither', async () => {
  const w = collect();
  const gh = compareGithub(compareOk(['extension/a.ts'], { status: 'diverged' }));
  assert.deepEqual(await flagsFor(gh, PR_RUN, w), { api: false, web: false });
});

test('PR: missing merge base -> both', async () => {
  const w = collect();
  assert.deepEqual(await flagsFor(compareGithub(compareOk(['README.md'], { merge_base_commit: null })), PR_RUN, w), BOTH);
  assert.equal(w.msgs.length, 1);
});

(async () => {
  for (const run of pending) {
    await run();
  }
  console.log(`\n${passed} passed`);
})().catch((err) => {
  console.error(err);
  process.exit(1);
});
