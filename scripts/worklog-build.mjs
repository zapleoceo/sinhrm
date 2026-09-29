#!/usr/bin/env node
// Regenerates the table in docs/worklog.md (between <!-- worklog:start --> / <!-- worklog:end -->)
// from fragments docs/worklog.d/*.md, newest first. No dependencies.
//   node scripts/worklog-build.mjs          # write docs/worklog.md
//   node scripts/worklog-build.mjs --check  # exit 1 if docs/worklog.md is out of date
// PR number: front matter `pr`, otherwise the squash-commit subject "... (#93)" that added the file.
import { readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';
import { FRAGMENT_DIR, buildRows, render } from './worklog-lib.mjs';

const WORKLOG = 'docs/worklog.md';
const check = process.argv.includes('--check');

const prFromGit = (path) => {
  try {
    const out = execFileSync('git', ['log', '--diff-filter=A', '--format=%s', '--', path], { encoding: 'utf8' });
    return out.trim().split('\n').pop().match(/\(#(\d+)\)\s*$/)?.[1];
  } catch {
    return undefined;
  }
};

const items = readdirSync(FRAGMENT_DIR)
  .filter((n) => n.endsWith('.md') && n !== 'README.md')
  .map((name) => {
    const path = join(FRAGMENT_DIR, name);
    return { name, content: readFileSync(path, 'utf8'), pr: prFromGit(path) };
  });

const current = readFileSync(WORKLOG, 'utf8');
const next = render(current, buildRows(items));
if (check) {
  if (next !== current) {
    console.error(`${WORKLOG} устарел: node scripts/worklog-build.mjs (на main это делает workflow worklog-build).`);
    process.exit(1);
  }
  console.log(`${WORKLOG} актуален (фрагментов: ${items.length})`);
} else if (next !== current) {
  writeFileSync(WORKLOG, next);
  console.log(`${WORKLOG} обновлён (фрагментов: ${items.length})`);
} else {
  console.log(`${WORKLOG} без изменений (фрагментов: ${items.length})`);
}
