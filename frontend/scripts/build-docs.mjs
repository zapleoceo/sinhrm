// Bundles the user-facing parts of ../docs (Markdown, single source) into public/docs/index.json for the in-app /docs page.
// Modules: only the plain-language sections ("Что это и зачем", "Как пользоваться", any "...простыми словами" heading).
// Guides: whole file, admin-only. Raw HTML in Markdown is escaped, links are limited to http(s)/mailto/in-app/anchors.
// Run automatically by `npm run build` / `npm start`; the output folder is git-ignored.
import { readFileSync, readdirSync, mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join, posix } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Marked } from 'marked';

const here = dirname(fileURLToPath(import.meta.url));
const DOCS = join(here, '..', '..', 'docs');
const OUT = join(here, '..', 'public', 'docs');
const REPO = 'https://github.com/zapleoceo/sinhrm/blob/main/docs/';

/** Group (uk UI label key) and audience per module page. Missing from the map → not published. */
export const MODULES = {
  recruiting: ['recruiting', 'all'], 'acquisition-channels': ['recruiting', 'admin'], 'hiring-requests': ['recruiting', 'all'],
  channels: ['recruiting', 'all'], extension: ['recruiting', 'all'], scripts: ['recruiting', 'all'], reports: ['recruiting', 'all'],
  overview: ['basics', 'all'], tasks: ['basics', 'all'], auth: ['basics', 'all'],
  people: ['people', 'all'], timeoff: ['people', 'all'], time: ['people', 'all'], documents: ['people', 'all'],
  assets: ['people', 'admin'], workflows: ['people', 'admin'],
  perform: ['perform', 'all'], pulse: ['perform', 'all'],
  desk: ['services', 'all'], knowledge: ['services', 'all'], 'safe-speak': ['services', 'all'],
  users: ['admin', 'admin'], integrations: ['admin', 'admin'], 'google-workspace': ['admin', 'admin'], 'mail-agent': ['admin', 'admin'],
  directory: ['admin', 'admin'], ai: ['admin', 'admin'], audit: ['admin', 'admin'],
};

const escapeHtml = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

/** Safe href or null: http(s), mailto, in-page anchors; relative .md links → in-app page or GitHub. */
export function safeHref(href, fromDir) {
  const h = (href ?? '').trim();
  if (/^(https?:|mailto:)/i.test(h)) return h;
  if (h.startsWith('#')) return h;
  if (/^[a-z][a-z0-9+.-]*:/i.test(h) || h.startsWith('//')) return null; // javascript:, data:, protocol-relative…
  const [path, hash] = h.split('#');
  const target = posix.normalize(posix.join(fromDir, path));
  const m = /^modules\/([a-z0-9-]+)\.md$/.exec(target);
  if (m && MODULES[m[1]]) return `/docs/${m[1]}${hash ? '#' + hash : ''}`;
  if (target.startsWith('..')) return null;
  return REPO + target + (hash ? '#' + hash : '');
}

function markedFor(fromDir) {
  return new Marked({
    gfm: true,
    renderer: {
      html: ({ text }) => escapeHtml(text),
      link({ href, tokens }) {
        const text = this.parser.parseInline(tokens);
        const safe = safeHref(href, fromDir);
        if (!safe) return text;
        const ext = /^https?:/i.test(safe) ? ' target="_blank" rel="noopener noreferrer"' : '';
        return `<a href="${escapeHtml(safe)}"${ext}>${text}</a>`;
      },
      image: ({ text }) => escapeHtml(text ?? ''),
    },
  });
}

/** Keeps the plain-language sections of a module page. */
export function userSections(md) {
  const lines = md.split(/\r?\n/);
  const keep = [];
  let on = false;
  let level = 0;
  for (const line of lines) {
    const h = /^(#{2,6})\s+(.*)$/.exec(line);
    if (h) {
      const lvl = h[1].length;
      const plain = /^(Что это и зачем|Как пользоваться)(?![\p{L}])/iu.test(h[2]) || /простыми словами/i.test(h[2]);
      if (plain && (!on || lvl <= level)) { on = true; level = lvl; }
      else if (on && lvl <= level) on = false;
    }
    if (on) keep.push(line);
  }
  return keep.join('\n').trim();
}

const plainText = (md) => md.replace(/`[^`]*`/g, (s) => s.slice(1, -1)).replace(/[#*_>|[\]()-]+/g, ' ').replace(/\s+/g, ' ').trim();
const titleOf = (md, slug) => (/^#\s+(.+)$/m.exec(md)?.[1] ?? slug).trim();

export function buildIndex() {
  const docs = [];
  for (const file of readdirSync(join(DOCS, 'modules')).sort()) {
    const slug = file.replace(/\.md$/, '');
    if (!file.endsWith('.md') || !MODULES[slug]) continue;
    const md = readFileSync(join(DOCS, 'modules', file), 'utf8');
    const body = userSections(md);
    if (!body) continue;
    const [group, audience] = MODULES[slug];
    docs.push({ slug, title: titleOf(md, slug), group, audience, html: markedFor('modules').parse(body), text: plainText(body) });
  }
  for (const file of readdirSync(join(DOCS, 'guides')).sort()) {
    if (!file.endsWith('.md')) continue;
    const md = readFileSync(join(DOCS, 'guides', file), 'utf8');
    const body = md.replace(/^#\s+.+$/m, '').trim();
    docs.push({ slug: 'guide-' + file.replace(/\.md$/, ''), title: titleOf(md, file), group: 'guides', audience: 'admin',
      html: markedFor('guides').parse(body), text: plainText(body) });
  }
  return docs;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  const docs = buildIndex();
  mkdirSync(OUT, { recursive: true });
  writeFileSync(join(OUT, 'index.json'), JSON.stringify(docs));
  console.log(`docs: ${docs.length} pages → public/docs/index.json`);
}
