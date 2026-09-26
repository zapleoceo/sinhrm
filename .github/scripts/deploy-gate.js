'use strict';

// Path-classification + "which deploy targets changed" logic for .github/workflows/deploy.yml.
// Kept out of inline YAML so it can be unit-tested with mocked inputs — see deploy-gate.test.js.

const WORKFLOW_FILE = '.github/workflows/deploy.yml';
const DEPLOY_WORKFLOW_ID = 'deploy.yml';
const DEPLOY_JOB_NAME = 'deploy';

function matchesApi(path) {
  return path.startsWith('backend/') || path === WORKFLOW_FILE;
}

function matchesWeb(path) {
  return path.startsWith('frontend/') || path.startsWith('docs/') || path === WORKFLOW_FILE;
}

/**
 * @param {string[]} paths changed file paths (repo-relative)
 * @returns {{api: boolean, web: boolean}}
 */
function classifyPaths(paths) {
  let api = false;
  let web = false;
  for (const p of paths || []) {
    if (matchesApi(p)) api = true;
    if (matchesWeb(p)) web = true;
  }
  return { api, web };
}

/**
 * Preview-only rule: a preview Web deployment must point at a preview API (never prod),
 * so a web change forces an api deploy too. Production (main) keeps api/web independent.
 * @param {{api: boolean, web: boolean}} flags
 * @param {boolean} isPreview
 */
function applyPreviewForce(flags, isPreview) {
  if (!isPreview) return flags;
  return { api: flags.api || flags.web, web: flags.web };
}

/**
 * Walks Deploy workflow runs on `main` (newest first) looking for the most recent one whose
 * `deploy` job concluded `success`. Returns its head_sha, or null if none is found (e.g. first
 * ever run) — callers should treat null as "deploy both" (api=true, web=true).
 *
 * @param {object} github octokit-like client: needs github.rest.actions.listWorkflowRuns and
 *   github.rest.actions.listJobsForWorkflowRun
 * @param {{owner: string, repo: string, workflow_id?: string}} repoInfo
 * @param {{perPage?: number, maxPages?: number}} [opts]
 * @returns {Promise<string|null>}
 */
async function findLastSuccessfulProductionSha(github, repoInfo, opts = {}) {
  const { owner, repo, workflow_id = DEPLOY_WORKFLOW_ID } = repoInfo;
  const perPage = opts.perPage || 100;
  const maxPages = opts.maxPages || 50;

  for (let page = 1; page <= maxPages; page++) {
    const { data } = await github.rest.actions.listWorkflowRuns({
      owner,
      repo,
      workflow_id,
      branch: 'main',
      status: 'success',
      per_page: perPage,
      page,
    });
    const runs = data.workflow_runs || [];
    if (runs.length === 0) return null;

    for (const run of runs) {
      const { data: jobsData } = await github.rest.actions.listJobsForWorkflowRun({
        owner,
        repo,
        run_id: run.id,
        per_page: 100,
      });
      const deployJob = (jobsData.jobs || []).find((j) => j.name === DEPLOY_JOB_NAME);
      if (deployJob && deployJob.conclusion === 'success') {
        return run.head_sha;
      }
    }

    if (runs.length < perPage) return null; // exhausted history, nothing found
  }
  return null; // gave up after maxPages — treat like "not found"
}

// GitHub compare returns at most 300 files; at that size the list may be truncated.
const COMPARE_FILE_LIMIT = 300;

/**
 * Validates a compare response. Returns the filename list, or null when the result cannot be
 * trusted (truncated file list, missing merge base, or — when requireAhead — the base is not an
 * ancestor of head, e.g. after a force-push). null means "deploy both".
 */
function filesFromCompare(data, { requireAhead }) {
  const files = data.files || [];
  if (files.length >= COMPARE_FILE_LIMIT) return null;
  if (!data.merge_base_commit || !data.merge_base_commit.sha) return null;
  if (requireAhead && (data.status === 'diverged' || data.status === 'behind')) return null;
  return files.map((f) => f.filename);
}

/**
 * Changed paths between a base sha and the pushed head sha (production case).
 * The base must be an ancestor of head; otherwise null (deploy both).
 * @returns {Promise<string[]|null>}
 */
async function getChangedPathsForPush(github, repoInfo, baseSha, headSha) {
  const { owner, repo } = repoInfo;
  const { data } = await github.rest.repos.compareCommitsWithBasehead({
    owner,
    repo,
    basehead: `${baseSha}...${headSha}`,
  });
  return filesFromCompare(data, { requireAhead: true });
}

/**
 * Changed paths between the PR's base branch and its head sha. The compare API diffs from the
 * merge-base (three-dot semantics); "diverged" is the normal state for a PR once main moves on,
 * so only a missing merge base or a truncated list forces "both" here.
 * @returns {Promise<string[]|null>}
 */
async function getChangedPathsForPr(github, repoInfo, baseBranch, headSha) {
  const { owner, repo } = repoInfo;
  const { data } = await github.rest.repos.compareCommitsWithBasehead({
    owner,
    repo,
    basehead: `${baseBranch}...${headSha}`,
  });
  return filesFromCompare(data, { requireAhead: false });
}

const BOTH = Object.freeze({ api: true, web: true });

/**
 * Full gate decision. Never throws: on ANY error (API failure, rate limit, 404 on compare after
 * a force-push, ...) it logs a warning and returns {api:true, web:true} — a skipped production
 * deploy is worse than a redundant one.
 * @param {object} p
 * @param {object} p.github octokit-like client
 * @param {{owner:string, repo:string}} p.repo
 * @param {object} p.run workflow_run payload (event, head_branch, head_sha, pull_requests)
 * @param {(msg:string)=>void} [p.warn]
 * @returns {Promise<{api:boolean, web:boolean}>}
 */
async function computeFlags({ github, repo, run, warn = console.warn }) {
  const isPush = run.event === 'push' && run.head_branch === 'main';
  try {
    let files;
    if (isPush) {
      const baseSha = await findLastSuccessfulProductionSha(github, { ...repo });
      if (!baseSha) {
        warn('deploy-gate: no previous successful production deploy found -> deploying both');
        return { ...BOTH };
      }
      files = await getChangedPathsForPush(github, repo, baseSha, run.head_sha);
    } else {
      const pr = (run.pull_requests || [])[0];
      const baseBranch = pr && pr.base && pr.base.ref ? pr.base.ref : 'main';
      files = await getChangedPathsForPr(github, repo, baseBranch, run.head_sha);
    }
    if (files === null) {
      warn('deploy-gate: compare result incomplete/untrusted (>=300 files, diverged/behind, or no merge base) -> deploying both');
      return { ...BOTH };
    }
    return applyPreviewForce(classifyPaths(files), !isPush);
  } catch (err) {
    warn(`deploy-gate: ${err && err.status ? `HTTP ${err.status}: ` : ''}${err && err.message ? err.message : err} -> deploying both`);
    return { ...BOTH };
  }
}

module.exports = {
  WORKFLOW_FILE,
  DEPLOY_WORKFLOW_ID,
  DEPLOY_JOB_NAME,
  classifyPaths,
  applyPreviewForce,
  findLastSuccessfulProductionSha,
  getChangedPathsForPush,
  getChangedPathsForPr,
  filesFromCompare,
  computeFlags,
  COMPARE_FILE_LIMIT,
};
