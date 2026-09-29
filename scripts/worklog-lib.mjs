// Shared logic for worklog fragments (docs/worklog.d/*.md). No dependencies.
// Used by scripts/worklog-build.mjs (local print), frontend/scripts/build-docs.mjs (in-app «Журнал работ» page)
// and scripts/worklog-check.mjs (CI job `worklog`). Fragments are the source of truth; nothing is committed back.

export const FRAGMENT_DIR = 'docs/worklog.d';
export const NAME_RE = /^\d{4}-\d{2}-\d{2}-[a-z0-9]+(?:-[a-z0-9]+)*\.md$/;
export const TEMPLATE = `---
date: 2026-09-29        # YYYY-MM-DD, обязательно
area: Recruiting        # модуль/область, обязательно
pr: 93                  # номер PR, необязательно (иначе из squash-коммита «… (#93)»)
---
Что изменилось, 1–3 строки простым текстом — [recruiting.md](modules/recruiting.md)`;

const isValidDate = (s) => {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return false;
  const d = new Date(`${s}T00:00:00Z`);
  return !Number.isNaN(d.getTime()) && d.toISOString().slice(0, 10) === s;
};

export const isFragmentPath = (p) =>
  p.startsWith(`${FRAGMENT_DIR}/`) && p.endsWith('.md') && !p.endsWith('/README.md');

/** Parses a fragment. Returns { meta, lines, errors }. */
export function parseFragment(name, content) {
  const errors = [];
  if (!NAME_RE.test(name)) errors.push(`имя файла «${name}» не по шаблону YYYY-MM-DD-slug.md (slug: a-z, 0-9, дефис)`);
  const m = content.replace(/\r\n/g, '\n').match(/^---\n([\s\S]*?)\n---\n?([\s\S]*)$/);
  if (!m) return { meta: {}, lines: [], errors: [...errors, 'нет front matter (блок между строками ---)'] };
  const meta = {};
  for (const raw of m[1].split('\n')) {
    const line = raw.replace(/\s+#\s.*$/, '').trim(); // "# comment" needs a space, so "pr: #92" survives
    if (!line) continue;
    const kv = line.match(/^([a-z]+):\s*(.*)$/);
    if (!kv) {
      errors.push(`непонятная строка front matter: «${raw}»`);
      continue;
    }
    meta[kv[1]] = kv[2].replace(/^["']|["']$/g, '').trim();
  }
  if (!meta.date) errors.push('front matter: нет date');
  else if (!isValidDate(meta.date)) errors.push(`front matter: date «${meta.date}» — нужен YYYY-MM-DD`);
  if (!meta.area) errors.push('front matter: нет area');
  if (meta.pr && !/^#?\d+$/.test(meta.pr)) errors.push(`front matter: pr «${meta.pr}» — нужен номер`);
  const lines = m[2].split('\n').map((l) => l.trim()).filter(Boolean);
  if (lines.length === 0) errors.push('пустой текст записи');
  if (lines.length > 3) errors.push(`текст ${lines.length} строк — максимум 3`);
  return { meta, lines, errors };
}

/**
 * CI verdict for a PR.
 * changes: [{ status: 'A'|'M'|'D'|'R100', path }], labels: string[], author: login,
 * fragments: { [path]: content } for every fragment added/modified in the PR.
 */
export function checkPr({ changes, labels = [], author = '', fragments = {} }) {
  const errors = [];
  for (const [path, content] of Object.entries(fragments)) {
    for (const e of parseFragment(path.split('/').pop(), content).errors) errors.push(`${path}: ${e}`);
  }
  if (errors.length) return { ok: false, reason: 'invalid-fragment', errors };
  if (changes.some((c) => c.status.startsWith('A') && isFragmentPath(c.path))) return { ok: true, reason: 'fragment' };
  if (labels.includes('no-worklog')) return { ok: true, reason: 'label' };
  if (/^dependabot(\[bot\])?$/.test(author)) return { ok: true, reason: 'dependabot' };
  if (changes.length > 0 && changes.every((c) => /^(docs|\.github)\//.test(c.path))) {
    return { ok: true, reason: 'docs-or-ci-only' };
  }
  return { ok: false, reason: 'missing', errors: [] };
}

const cell = (s) => String(s).replace(/\|/g, '\\|');

/** Table rows (newest first) from [{ name, content, pr? }]. Throws on an invalid fragment. */
export function buildRows(items) {
  const parsed = items.map(({ name, content, pr }) => {
    const f = parseFragment(name, content);
    if (f.errors.length) throw new Error(`${name}: ${f.errors.join('; ')}`);
    const num = String(f.meta.pr || pr || '').replace(/^#/, '');
    return { name, date: f.meta.date, text: `${f.meta.area}: ${f.lines.join(' ')}`, pr: num ? `#${num}` : '—' };
  });
  parsed.sort((a, b) => b.date.localeCompare(a.date) || b.name.localeCompare(a.name));
  return parsed.map((r) => `| ${r.date} | ${cell(r.text)} | ${r.pr} |`);
}

/** Static pre-fragment rows: table rows of docs/worklog.md below the "### Ранее" heading. */
export function historyRows(worklogMd) {
  const at = worklogMd.search(/^### Ранее/m);
  if (at < 0) return [];
  return worklogMd
    .slice(at)
    .split(/\r?\n/)
    .filter((l) => /^\| \d{4}-\d{2}-\d{2} \|/.test(l));
}

/** Chronological journal (Markdown): fragments newest first, then the static history. */
export function journalMarkdown(items, worklogMd) {
  const rows = [...buildRows(items), ...historyRows(worklogMd)];
  return ['| Дата | Что | PR |', '|---|---|---|', ...rows].join('\n');
}

/** PR number from the commit that added the file ("... (#93)" squash subject); undefined without git history. */
export function prFromGit(execFileSync, path, cwd) {
  try {
    const out = execFileSync('git', ['log', '--diff-filter=A', '--format=%s', '--', path], { encoding: 'utf8', cwd, stdio: ['ignore', 'pipe', 'ignore'] });
    return out.trim().split('\n').pop().match(/\(#(\d+)\)\s*$/)?.[1];
  } catch {
    return undefined;
  }
}
