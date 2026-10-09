// CI guard (job `lint`): MySQL 8.4 is the only database of the project (ADR 0010). Fails when a tracked file names
// another database, its driver or hosting, or uses SQL that does not exist in MySQL (case-insensitive LIKE operator,
// binary JSON type, sequences, casts to text, catalog functions). There is no allowlist: only lock files are skipped.
// The words are assembled from parts so this file and its test do not match themselves.
// Run: node scripts/mysql-only-guard.mjs  (tests: node --test scripts/mysql-only-guard.test.mjs)
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

const WORDS = ['post' + 'gres', 'pg' + 'sql', 'i' + 'like', 'next' + 'val', '\\bjson' + 'b\\b', '\\bp' + 'g_'];
/** The hosting name as a word, but not inside identifiers (OneOnOne) or as a config file extension (phpstan.neon). */
const HOSTING = '(?<![.\\w-])' + 'ne' + 'on' + '\\b';
export const FORBIDDEN = new RegExp(`${WORDS.join('|')}|${HOSTING}`, 'i');
/** SQL cast to text (lower case, as written in SQL); a static PHP call `Foo::text(` and an enum case `::Text` are not casts. */
export const CAST = new RegExp('::' + 'te' + 'xt\\b(?!\\s*\\()');

/** Dependency lock files list third-party package names; they are generated, not written by us. */
export const SKIPPED = ['package-lock.json', 'composer.lock'];

export const isSkipped = (path) => SKIPPED.includes(path.split('/').pop()) || path.split('/').some((p) => p === 'vendor' || p === 'node_modules');

/** Findings `path:line: text` for one file's content (binary content is skipped). */
export function findings(path, content) {
  if (isSkipped(path) || content.includes('\u0000')) return [];
  const out = [];
  content.split('\n').forEach((line, i) => {
    if (FORBIDDEN.test(line) || CAST.test(line)) out.push(`${path}:${i + 1}: ${line.trim().slice(0, 160)}`);
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
    console.error(`MySQL only (ADR 0010): ${all.length} mention(s) of another database or its SQL:`);
    for (const f of all) console.error(`  ${f}`);
    console.error('Use MySQL 8.4 wording and SQL (docs/adr/0010-mysql.md, docs/guides/development.md); there are no exceptions.');
    process.exit(1);
  }
  console.log(`MySQL only (ADR 0010): ${files.length} tracked files checked, no mentions of another database.`);
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) main();
