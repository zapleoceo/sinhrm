#!/usr/bin/env node
// CI job `worklog`: a PR must add docs/worklog.d/<YYYY-MM-DD>-<slug>.md unless it has the label `no-worklog`,
// touches only docs/ or .github/, or comes from dependabot. Every added/modified fragment is validated.
//   node scripts/worklog-check.mjs <base-ref>     env: PR_LABELS="a,b" PR_AUTHOR=<login>
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { TEMPLATE, checkPr, isFragmentPath, parseNameStatus } from './worklog-lib.mjs';

const base = process.argv[2] || 'origin/main';
// -z + quotepath=false: paths with unicode, spaces or tabs come back verbatim instead of quoted octal escapes.
const diff = execFileSync('git', ['-c', 'core.quotepath=false', 'diff', '-z', '--name-status', '--no-renames', `${base}...HEAD`], { encoding: 'utf8' });
const changes = parseNameStatus(diff);
const fragments = Object.fromEntries(
  changes.filter((c) => /^[AMR]/.test(c.status) && isFragmentPath(c.path)).map((c) => [c.path, readFileSync(c.path, 'utf8')]),
);
const labels = (process.env.PR_LABELS || '').split(',').map((s) => s.trim()).filter(Boolean);
const res = checkPr({ changes, labels, author: process.env.PR_AUTHOR || '', fragments });

if (res.ok) {
  console.log(`worklog: OK (${res.reason})`);
} else {
  if (res.reason === 'invalid-fragment') for (const e of res.errors) console.log(`::error::${e}`);
  else console.log('::error::PR без записи в журнал работ: добавьте docs/worklog.d/<YYYY-MM-DD>-<slug>.md или метку no-worklog.');
  console.log(`\nШаблон (подробно — docs/worklog.d/README.md):\n${TEMPLATE}\n`);
  console.log('Без записи можно: метка no-worklog (затем Re-run job), PR только в docs/ или .github/, dependabot.');
  process.exit(1);
}
