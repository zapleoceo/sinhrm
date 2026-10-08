// CI guard (job `docs`): relative links in the repository Markdown point to an existing file or folder, and an
// anchor `file.md#heading` / `#heading` names a real heading of that file (GitHub slug rules, Cyrillic included).
// External links (http(s), mailto and other schemes) are not checked. Code spans and fenced code blocks are skipped.
// Scope: README.md, CLAUDE.md, AGENTS.md and docs/**/*.md (tracked files). Journal fragments docs/worklog.d/*.md
// link relative to docs/ (they are rendered in «Журнал работ»), every other file — relative to its own folder.
// Run: node scripts/docs-links-check.mjs  (tests: node --test scripts/docs-links-check.test.mjs)
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, statSync } from 'node:fs';
import { posix } from 'node:path';
import { pathToFileURL } from 'node:url';

export const ROOT_FILES = ['README.md', 'CLAUDE.md', 'AGENTS.md'];
export const inScope = (path) => ROOT_FILES.includes(path) || (path.startsWith('docs/') && path.endsWith('.md'));

/** Content without fenced code blocks and inline code spans; line numbers are preserved. */
export function stripCode(md) {
  const lines = md.split(/\r?\n/);
  let fence = null;
  return lines
    .map((line) => {
      const f = /^\s*(`{3,}|~{3,})/.exec(line);
      if (fence) {
        if (f && f[1][0] === fence[0] && f[1].length >= fence.length) fence = null;
        return '';
      }
      if (f) {
        fence = f[1];
        return '';
      }
      return line.replace(/(`+)[\s\S]*?\1/g, (s) => ' '.repeat(s.length));
    })
    .join('\n');
}

/** Inline links and images `[text](target)` / `[text](<target> "title")` with their line numbers. */
export function extractLinks(md) {
  const out = [];
  stripCode(md)
    .split('\n')
    .forEach((line, i) => {
      const re = /\]\(\s*(<[^>]*>|[^\s)]+)(?:\s+(?:"[^"]*"|'[^']*'))?\s*\)/g;
      for (let m; (m = re.exec(line)); ) out.push({ line: i + 1, target: m[1].replace(/^<|>$/g, '') });
    });
  return out;
}

/** GitHub heading slug: lower case, punctuation dropped (letters of any script, digits, `_`, `-` kept), spaces → `-`. */
export function slugify(heading) {
  return heading
    .replace(/!?\[([^\]]*)\]\([^)]*\)/g, '$1') // links → their text
    .replace(/<[^>]+>/g, '')
    .trim()
    .toLowerCase()
    .replace(/[^\p{L}\p{M}\p{N}\p{Pc}\- ]/gu, '')
    .replace(/ /g, '-');
}

/** Set of anchors of a Markdown file: ATX headings, duplicates suffixed `-1`, `-2`… as GitHub does. */
export function anchorsOf(md) {
  const seen = new Map();
  const anchors = new Set();
  for (const line of stripHeadingCode(md)) {
    const h = /^ {0,3}#{1,6}\s+(.*?)\s*#*\s*$/.exec(line);
    if (!h) continue;
    const base = slugify(h[1]);
    const n = seen.get(base) ?? 0;
    seen.set(base, n + 1);
    anchors.add(n ? `${base}-${n}` : base);
  }
  return anchors;
}

/** Lines outside fenced code blocks; inline code keeps its text (GitHub slugs include it without backticks). */
function stripHeadingCode(md) {
  let fence = null;
  const out = [];
  for (const line of md.split(/\r?\n/)) {
    const f = /^\s*(`{3,}|~{3,})/.exec(line);
    if (fence) {
      if (f && f[1][0] === fence[0] && f[1].length >= fence.length) fence = null;
      continue;
    }
    if (f) {
      fence = f[1];
      continue;
    }
    out.push(line.replace(/`/g, ''));
  }
  return out;
}

const EXTERNAL = /^([a-z][a-z0-9+.-]*:|\/\/)/i;

/**
 * Folder links of a file are resolved from. Journal fragments are rendered inside «Журнал работ» next to
 * docs/worklog.md, so their links are written relative to `docs/` (docs/worklog.d/README.md).
 */
export function baseDir(path) {
  if (/^docs\/worklog\.d\/(?!README\.md$)[^/]+\.md$/.test(path)) return 'docs';
  return posix.dirname(path);
}

/**
 * Broken links of one file. `fs` abstracts the repository: exists(path), isDir(path), read(path) — repo-relative
 * POSIX paths. Returns `{ line, target, reason }[]`.
 */
export function brokenLinks(path, content, fs) {
  const dir = baseDir(path);
  const res = [];
  for (const { line, target } of extractLinks(content)) {
    if (!target || EXTERNAL.test(target)) continue;
    const [rawFile, rawHash] = target.split('#');
    let file;
    try {
      file = decodeURIComponent(rawFile);
    } catch {
      file = rawFile;
    }
    const resolved = file ? posix.normalize(posix.join(dir, file)).replace(/\/$/, '') : path;
    if (resolved.startsWith('..') || resolved.startsWith('/')) {
      res.push({ line, target, reason: 'ссылка ведёт за пределы репозитория' });
      continue;
    }
    if (!fs.exists(resolved)) {
      res.push({ line, target, reason: 'файл не найден' });
      continue;
    }
    if (rawHash === undefined || rawHash === '' || !resolved.endsWith('.md') || fs.isDir(resolved)) continue;
    let hash;
    try {
      hash = decodeURIComponent(rawHash).toLowerCase();
    } catch {
      hash = rawHash.toLowerCase();
    }
    if (!anchorsOf(fs.read(resolved)).has(hash)) res.push({ line, target, reason: 'нет заголовка с таким якорем' });
  }
  return res;
}

/** Repository adapter on top of the working tree and `git ls-files`. */
export function repoFs(cwd = process.cwd()) {
  const tracked = execFileSync('git', ['ls-files', '-z'], { cwd, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
    .split('\0')
    .filter(Boolean);
  const files = new Set(tracked);
  const dirs = new Set();
  for (const f of tracked) for (let d = posix.dirname(f); d !== '.'; d = posix.dirname(d)) dirs.add(d);
  const cache = new Map();
  return {
    tracked,
    exists: (p) => files.has(p) || dirs.has(p) || existsSync(posix.join(cwd, p)),
    isDir: (p) => dirs.has(p) || (existsSync(posix.join(cwd, p)) && statSync(posix.join(cwd, p)).isDirectory()),
    read: (p) => {
      if (!cache.has(p)) cache.set(p, readFileSync(posix.join(cwd, p), 'utf8'));
      return cache.get(p);
    },
  };
}

export function checkRepo(fs) {
  const all = [];
  for (const path of fs.tracked.filter(inScope).sort()) {
    for (const b of brokenLinks(path, fs.read(path), fs)) all.push({ path, ...b });
  }
  return all;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const fs = repoFs();
  const files = fs.tracked.filter(inScope).length;
  const broken = checkRepo(fs);
  for (const b of broken) console.log(`::error file=${b.path},line=${b.line}::${b.reason}: ${b.target}`);
  console.log(`docs-links-check: файлов ${files}, мёртвых ссылок ${broken.length}`);
  process.exit(broken.length ? 1 : 0);
}
