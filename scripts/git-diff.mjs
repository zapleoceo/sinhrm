// Shared git-diff helpers for the PR checks (docs-check, tests-check, worklog-check). No dependencies.
import { execFileSync } from 'node:child_process';

/**
 * Parses `git diff -z --name-status --no-renames` output (NUL-separated, so unicode, spaces and tabs in paths survive).
 * Returns [{ status, path, oldPath? }]; a rename/copy record (not produced with --no-renames) keeps both paths.
 */
export function parseNameStatus(raw) {
  const tokens = raw.split('\0');
  const changes = [];
  for (let i = 0; i < tokens.length && tokens[i]; ) {
    const status = tokens[i++];
    if (/^[RC]/.test(status)) {
      changes.push({ status, oldPath: tokens[i], path: tokens[i + 1] });
      i += 2;
    } else {
      changes.push({ status, path: tokens[i++] });
    }
  }
  return changes;
}

/** Files changed between `base` and HEAD (three-dot: since the merge base). quotepath=false + -z keep unicode paths verbatim. */
export function gitChanges(base, exec = execFileSync) {
  return parseNameStatus(
    exec('git', ['-c', 'core.quotepath=false', 'diff', '-z', '--name-status', '--no-renames', `${base}...HEAD`], { encoding: 'utf8' }),
  );
}

/** Unified diff of one file ignoring whitespace-only and blank-line changes (zero context). */
export function gitDiffIgnoringWhitespace(base, path, exec = execFileSync) {
  return exec('git', ['-c', 'core.quotepath=false', 'diff', '-U0', '-w', '--ignore-blank-lines', `${base}...HEAD`, '--', path], { encoding: 'utf8' });
}

/** PR labels from the env (PR_LABELS="a,b") and author (PR_AUTHOR). */
export function prContextFromEnv(env = process.env) {
  return {
    labels: (env.PR_LABELS || '').split(',').map((s) => s.trim()).filter(Boolean),
    author: env.PR_AUTHOR || '',
  };
}
