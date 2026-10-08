// CI guard (job `lint`): MySQL 8.4 is the only database of the project (ADR 0011). Fails when the name of the former
// database, its driver, its hosting or its case-insensitive LIKE operator appears in a tracked file outside the
// one-off cutover tool and the history. The words are assembled from parts so this file does not match itself.
// Run: node scripts/mysql-only-guard.mjs  (tests: node --test scripts/mysql-only-guard.test.mjs)
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

const WORDS = ['post' + 'gres', 'pg' + 'sql', 'i' + 'like'];
/** "neon" as a word, but not the `.neon` extension (phpstan.neon, extension.neon). */
const HOSTING = '(?<![.\\w-])' + 'ne' + 'on' + '\\b';
export const FORBIDDEN = new RegExp(`${WORDS.join('|')}|${HOSTING}`, 'i');

/**
 * Paths (prefixes) that may mention it. REMOVE the cutover entries together with the tool after the cutover
 * (docs/guides/mysql-cutover.md, "Удаление после cutover"; PROD-51).
 */
export const ALLOWED = [
  // history: decisions and journal are never rewritten
  'docs/adr/',
  'docs/worklog.md',
  'docs/worklog.d/',
  // one-off cutover tool db:transfer-to-mysql and its runbook (remove after cutover)
  'backend/app/Modules/Core/Transfer/',
  'backend/tests/Unit/Core/Transfer/',
  'backend/tests/Feature/Core/Transfer/',
  '.github/workflows/mysql-data-transfer.yml',
  'docs/guides/mysql-cutover.md',
  'docs/tasks/HRM-2-mysql-migration.md',
  // owned by draft PR #174 (HRM-38, MySQL backup/restore): it rewrites these files; drop the entries in its rebase
  'docs/guides/backup-restore.md',
  'docs/guides/itstep-app-handoff.md',
  'scripts/backup-restore-proof.test.mjs',
];

export const isAllowed = (path) => ALLOWED.some((prefix) => path === prefix || (prefix.endsWith('/') && path.startsWith(prefix)));

/** Findings `path:line: text` for one file's content (binary content is skipped). */
export function findings(path, content) {
  if (isAllowed(path) || content.includes('\u0000')) return [];
  const out = [];
  content.split('\n').forEach((line, i) => {
    if (FORBIDDEN.test(line)) out.push(`${path}:${i + 1}: ${line.trim().slice(0, 160)}`);
  });
  return out;
}

function main() {
  const files = execFileSync('git', ['ls-files', '-z'], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
    .split('\u0000')
    .filter(Boolean);
  const all = [];
  for (const path of files) {
    let content;
    try {
      content = readFileSync(path, 'utf8');
    } catch {
      continue; // deleted in the working tree / submodule
    }
    all.push(...findings(path, content));
  }
  if (all.length > 0) {
    console.error(`MySQL only (ADR 0011): ${all.length} mention(s) of the former database outside the cutover tool:`);
    for (const f of all) console.error(`  ${f}`);
    console.error('Use MySQL 8.4 wording/SQL (docs/guides/development.md); the only exception is backend/app/Modules/Core/Transfer/.');
    process.exit(1);
  }
  console.log(`MySQL only (ADR 0011): ${files.length} tracked files checked, no mentions outside the cutover tool.`);
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) main();
