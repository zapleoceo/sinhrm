// Pure logic of the PR checks `docs-check` (documentation is updated with the code) and `tests-check` (tests are updated
// with the code). No dependencies, no git access: scripts/docs-check.mjs and scripts/tests-check.mjs feed it the changes.
// A change is { status: 'A'|'M'|'D'|..., path }. These checks are a floor, not proof of coverage: coverage itself is
// enforced by the backend/frontend/extension jobs.

export const NO_TESTS_LABEL = 'no-tests-needed';
/** A doc edit counts only if it adds at least one line with this many non-whitespace characters (a space or a dot does not). */
export const MIN_DOC_LINE_CHARS = 20;

const kebab = (name) =>
  name.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase().replace(/^time-off$/, 'timeoff');

const isTestFile = (p) =>
  /\.(spec|test)\.[cm]?[jt]s$/.test(p) || p.startsWith('extension/tests/') || p.startsWith('backend/tests/');
const isLive = (c) => !c.status.startsWith('D');

/** Doc keys (docs/modules/<key>.md) that the non-test code changes of a PR require. */
export function requiredModuleDocs(changes) {
  const keys = new Set();
  for (const { path } of changes) {
    if (isTestFile(path)) continue;
    let m;
    if ((m = path.match(/^backend\/app\/Modules\/([^/]+)\//))) keys.add(kebab(m[1]));
    else if ((m = path.match(/^frontend\/src\/app\/features\/([^/]+)\//))) keys.add(m[1]);
    else if (path.startsWith('frontend/src/app/core/')) keys.add('core');
    else if (path.startsWith('extension/') && !/(^|\/)package(-lock)?\.json$/.test(path)) keys.add('extension');
  }
  return [...keys].sort().map((k) => `docs/modules/${k}.md`);
}

/** Migrations, routes, config and bootstrap change behaviour without living in a module folder: they need *some* doc edit. */
export const isCrossCutting = (p) => /^backend\/(database\/migrations|routes|config|bootstrap)\//.test(p);
const isProductDoc = (p) => /^docs\/(modules|architecture|guides|adr)\/.+\.md$/.test(p);

/** True if a `git diff -U0 -w --ignore-blank-lines` text adds a line with >= MIN_DOC_LINE_CHARS non-whitespace characters. */
export function hasSubstantiveAddition(diffText) {
  return diffText
    .split('\n')
    .some((l) => l.startsWith('+') && !l.startsWith('+++') && l.slice(1).replace(/\s+/g, '').length >= MIN_DOC_LINE_CHARS);
}

/**
 * docs-check verdict. `isSubstantive(path)` says whether the doc edit is real (see hasSubstantiveAddition).
 * Returns { ok, missing[] (doc not touched / not existing), weak[] (touched but only whitespace/trivial), crossCutting }.
 */
export function checkDocs({ changes, isSubstantive }) {
  const live = new Map(changes.filter(isLive).map((c) => [c.path, c]));
  const missing = [];
  const weak = [];
  for (const doc of requiredModuleDocs(changes)) {
    if (!live.has(doc)) missing.push(doc);
    else if (!isSubstantive(doc)) weak.push(doc);
  }
  const needsAnyDoc = changes.some((c) => isCrossCutting(c.path));
  const crossCutting = needsAnyDoc && ![...live.keys()].some((p) => isProductDoc(p) && isSubstantive(p));
  return { ok: missing.length === 0 && weak.length === 0 && !crossCutting, missing, weak, crossCutting };
}

const BACKEND_NO_TEST_DIRS = new Set(['Providers', 'Contracts', 'Enums', 'Models', 'Database', 'DTO', 'Exceptions']);
const FRONTEND_NO_TEST_FILE = /(\.spec|\.d|\.model|\.models|\.routes|\.types)\.ts$/;

/** Test scope a source file belongs to, or null when it needs no test (config, types, providers, ...). */
export function codeScope(path) {
  let m;
  if ((m = path.match(/^backend\/app\/Modules\/([^/]+)\/(.+\.php)$/))) {
    const [first] = m[2].split('/');
    return m[2].includes('/') && BACKEND_NO_TEST_DIRS.has(first) ? null : `backend:${m[1]}`;
  }
  if ((m = path.match(/^frontend\/src\/app\/(features\/[^/]+|core)\/.+\.ts$/))) {
    return FRONTEND_NO_TEST_FILE.test(path) ? null : `frontend:${m[1]}`;
  }
  if (/^extension\/src\/.+\.ts$/.test(path)) {
    return /(^|\/)types\.ts$|\.d\.ts$/.test(path) ? null : 'extension';
  }
  return null;
}

/** Test scope a test file covers, or null. */
export function testScope(path) {
  let m;
  if ((m = path.match(/^backend\/tests\/(?:Feature|Unit)\/([^/]+)\/.+\.php$/))) return `backend:${m[1]}`;
  if ((m = path.match(/^frontend\/src\/app\/(features\/[^/]+|core)\/.+\.spec\.ts$/))) return `frontend:${m[1]}`;
  if (/^extension\/tests\/.+\.ts$/.test(path)) return 'extension';
  return null;
}

const HINTS = {
  backend: (s) => `backend/tests/{Feature,Unit}/${s}/`,
  frontend: (s) => `frontend/src/app/${s}/**/*.spec.ts`,
  extension: () => 'extension/tests/',
};

/**
 * tests-check verdict: every module/feature/extension touched by non-deleted source changes needs an added or modified test
 * in the same scope. Exempt: label `no-tests-needed` (a reviewer's call for behaviour-neutral changes), dependabot.
 */
export function checkTests({ changes, labels = [], author = '' }) {
  const code = new Set(changes.filter(isLive).map((c) => codeScope(c.path)).filter(Boolean));
  if (code.size === 0) return { ok: true, reason: 'no-code', missing: [] };
  const tested = new Set(changes.filter(isLive).map((c) => testScope(c.path)).filter(Boolean));
  const missing = [...code].filter((s) => !tested.has(s)).sort();
  if (missing.length === 0) return { ok: true, reason: 'tests', missing };
  if (labels.includes(NO_TESTS_LABEL)) return { ok: true, reason: 'label', missing };
  if (author === 'dependabot[bot]') return { ok: true, reason: 'dependabot', missing };
  return {
    ok: false,
    reason: 'missing',
    missing,
    hints: missing.map((s) => {
      const [kind, rest] = s.split(/:(.*)/);
      return `${s} → ${HINTS[kind](rest)}`;
    }),
  };
}
