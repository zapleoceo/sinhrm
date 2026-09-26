/** Collapsible sidebar groups: which group owns a URL and how the open/closed state is remembered. */

export type NavGroupId = 'recruiting' | 'people' | 'perform' | 'services' | 'admin';

export const NAV_GROUP_IDS: readonly NavGroupId[] = ['recruiting', 'people', 'perform', 'services', 'admin'];

/** URL prefixes per group. The longest matching prefix wins (so /reports/catalog → services, /reports → recruiting). */
const GROUP_PREFIXES: Readonly<Record<NavGroupId, readonly string[]>> = {
  recruiting: ['/candidates', '/vacancies', '/inbox', '/hiring-requests', '/reports'],
  people: ['/people', '/timeoff', '/time', '/me/documents', '/workflows'],
  perform: ['/perform', '/pulse'],
  services: ['/desk', '/knowledge', '/safe-speak', '/reports/catalog', '/reports/builder'],
  admin: ['/desk/queue', '/safe-speak/inbox', '/admin', '/status'],
};

/** Group that contains the given URL, or null for top-level pages (Overview, Tasks, profile…). */
export function groupForUrl(url: string): NavGroupId | null {
  const path = url.split(/[?#]/)[0];
  let best: NavGroupId | null = null;
  let bestLen = 0;
  for (const id of NAV_GROUP_IDS) {
    for (const prefix of GROUP_PREFIXES[id]) {
      const hit = path === prefix || path.startsWith(prefix + '/');
      if (hit && prefix.length > bestLen) {
        best = id;
        bestLen = prefix.length;
      }
    }
  }
  return best;
}

const KEY_PREFIX = 'sinhrm.nav.expanded.';

/** Reads the remembered open groups for a user; any storage problem → empty set. */
export function loadExpanded(userId: number | string): Set<NavGroupId> {
  try {
    const raw = localStorage.getItem(KEY_PREFIX + userId);
    const parsed: unknown = raw ? JSON.parse(raw) : [];
    if (!Array.isArray(parsed)) return new Set();
    return new Set(parsed.filter((v): v is NavGroupId => NAV_GROUP_IDS.includes(v as NavGroupId)));
  } catch {
    return new Set();
  }
}

/** Saves open groups for a user; silently ignores unavailable storage. */
export function saveExpanded(userId: number | string, groups: ReadonlySet<NavGroupId>): void {
  try {
    localStorage.setItem(KEY_PREFIX + userId, JSON.stringify([...groups]));
  } catch {
    /* storage unavailable — state lives only in memory */
  }
}
