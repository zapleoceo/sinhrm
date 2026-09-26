import { UserRole } from '../../../core/auth/auth.model';

/** One page of public/docs/index.json (built by scripts/build-docs.mjs from ../docs). */
export interface DocPage {
  slug: string;
  title: string;
  group: DocGroup;
  audience: 'all' | 'admin';
  /** Rendered at build time with raw HTML escaped and links whitelisted; Angular's [innerHTML] sanitizer is a second layer. */
  html: string;
  /** Plain text for search. */
  text: string;
}

export type DocGroup = 'basics' | 'recruiting' | 'people' | 'perform' | 'services' | 'admin' | 'guides';
export const DOC_GROUPS: readonly DocGroup[] = ['basics', 'recruiting', 'people', 'perform', 'services', 'admin', 'guides'];

export interface DocHit {
  doc: DocPage;
  snippet: string;
}

/** Admin-only pages are hidden for everyone except admin/superadmin (the static index is not a secret: the repo is public). */
export function visibleDocs(docs: readonly DocPage[], roles: readonly UserRole[]): DocPage[] {
  const admin = roles.includes('admin') || roles.includes('superadmin');
  return docs.filter((d) => d.audience === 'all' || admin);
}

export function groupDocs(docs: readonly DocPage[]): { group: DocGroup; docs: DocPage[] }[] {
  return DOC_GROUPS.map((group) => ({ group, docs: docs.filter((d) => d.group === group) })).filter((g) => g.docs.length > 0);
}

/** Case-insensitive search over title and text; every word must match. Title hits first. */
export function searchDocs(docs: readonly DocPage[], query: string): DocHit[] {
  const words = query.toLowerCase().split(/\s+/).filter((w) => w.length > 0);
  if (words.length === 0) return [];
  const hits: { hit: DocHit; score: number }[] = [];
  for (const doc of docs) {
    const title = doc.title.toLowerCase();
    const text = doc.text.toLowerCase();
    if (!words.every((w) => title.includes(w) || text.includes(w))) continue;
    const at = text.indexOf(words[0]);
    const snippet = at < 0 ? doc.text.slice(0, 140) : (at > 40 ? '…' : '') + doc.text.slice(Math.max(0, at - 40), at + 100) + '…';
    hits.push({ hit: { doc, snippet }, score: words.filter((w) => title.includes(w)).length });
  }
  return hits.sort((a, b) => b.score - a.score).map((h) => h.hit);
}
