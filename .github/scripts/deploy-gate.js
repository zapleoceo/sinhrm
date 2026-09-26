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

/**
 * Changed file paths between a base sha and the pushed head sha (production case).
 * @returns {Promise<string[]>}
 */
async function getChangedPathsForPush(github, repoInfo, baseSha, headSha) {
  const { owner, repo } = repoInfo;
  const { data } = await github.rest.repos.compareCommitsWithBasehead({
    owner,
    repo,
    basehead: `${baseSha}...${headSha}`,
  });
  return (data.files || []).map((f) => f.filename);
}

/**
 * Changed file paths between the PR's base branch and its head sha. The GitHub compare API
 * diffs from the merge-base of the two refs (three-dot semantics), not from the base branch's
 * current tip, matching what a real PR diff shows.
 * @returns {Promise<string[]>}
 */
async function getChangedPathsForPr(github, repoInfo, baseBranch, headSha) {
  const { owner, repo } = repoInfo;
  const { data } = await github.rest.repos.compareCommitsWithBasehead({
    owner,
    repo,
    basehead: `${baseBranch}...${headSha}`,
  });
  return (data.files || []).map((f) => f.filename);
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
};
