#!/usr/bin/env node
// Prints the chronological journal (Markdown table, newest first): fragments docs/worklog.d/*.md
// followed by the static history in docs/worklog.md («Ранее»). Nothing is written or committed:
// the same journal is built into the in-app «Довідка» by frontend/scripts/build-docs.mjs.
//   node scripts/worklog-build.mjs --print   # print the table (exit 1 on an invalid fragment)
import { readFileSync, readdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { FRAGMENT_DIR, journalMarkdown, prFromGit } from './worklog-lib.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');

/** Reads all fragments: [{ name, content, pr }]. */
export function readFragments(repoRoot = root) {
  const dir = join(repoRoot, FRAGMENT_DIR);
  return readdirSync(dir)
    .filter((n) => n.endsWith('.md') && n !== 'README.md')
    .map((name) => ({
      name,
      content: readFileSync(join(dir, name), 'utf8'),
      pr: prFromGit(execFileSync, `${FRAGMENT_DIR}/${name}`, repoRoot),
    }));
}

export function buildJournal(repoRoot = root) {
  return journalMarkdown(readFragments(repoRoot), readFileSync(join(repoRoot, 'docs', 'worklog.md'), 'utf8'));
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  try {
    console.log(buildJournal());
  } catch (e) {
    console.error(`worklog: ${e.message}`);
    process.exit(1);
  }
}
