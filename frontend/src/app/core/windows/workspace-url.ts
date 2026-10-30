import { Router, UrlSegment, UrlSegmentGroup, UrlTree, convertToParamMap } from '@angular/router';
import { WindowSlot, WINDOW_SLOTS, windowRouteByPath, windowRouteDefinitions } from './window-context';
import { decodeWindowQuery } from './window-query-codec';
import { WorkspaceSnapshot } from './workspace-snapshot.store';

const CONTROL_KEYS = new Set(['osActive', 'osMin', 'osMax', 'osPrimary']);
const CREDENTIAL_QUERY_KEY = /(?:auth|token|password|secret|session|cookie|csrf|bearer)/i;

export function workspaceVisibleUrl(router: Router, tree: UrlTree, preferredSlot?: WindowSlot): string {
  const active = preferredSlot && findOutlet(tree.root, preferredSlot)
    ? preferredSlot
    : tree.queryParams['osPrimary'] === '1'
      ? null
      : WINDOW_SLOTS.find((slot) => tree.queryParams['osActive'] === slot)
        ?? WINDOW_SLOTS.find((slot) => findOutlet(tree.root, slot));
  const branch = active ? findOutlet(tree.root, active) : null;
  const primary = branch ?? tree.root.children['primary'];
  const segments = flattenPath(primary).filter(Boolean);
  if (branch && segments[0] === 'window') segments.shift();
  const decodedQuery = branch ? decodeWindowQuery(convertToParamMap(windowQuery(branch))) : null;
  const query = decodedQuery
    ? Object.fromEntries(decodedQuery.keys.map((key) => {
      const values = decodedQuery.getAll(key);
      return [key, values.length > 1 ? values : values[0]];
    }))
    : Object.fromEntries(Object.entries(tree.queryParams).filter(([key]) => !CONTROL_KEYS.has(key)));
  const canonicalRoot = new UrlSegmentGroup([], segments.length
    ? { primary: new UrlSegmentGroup(segments.map((segment) => new UrlSegment(segment, {})), {}) }
    : {});
  const canonical = new UrlTree(canonicalRoot, query, tree.fragment ?? null);
  return router.serializeUrl(canonical);
}

export function createWorkspaceSnapshot(
  router: Router,
  tree: UrlTree,
  userId: number,
  hasModule: (module: string) => boolean,
): WorkspaceSnapshot | null {
  const snapshot: WorkspaceSnapshot = {
    version: 1,
    userId,
    internalUrl: router.serializeUrl(tree),
    visibleUrl: workspaceVisibleUrl(router, tree),
  };
  return validateWorkspaceSnapshot(router, snapshot, userId, hasModule) ? snapshot : null;
}

export function validateWorkspaceSnapshot(router: Router, snapshot: WorkspaceSnapshot, userId: number, hasModule: (module: string) => boolean): boolean {
  if (snapshot.userId !== userId || snapshot.internalUrl.length > 40_960 || snapshot.visibleUrl.length > 4096
    || !snapshot.internalUrl.startsWith('/') || snapshot.internalUrl.startsWith('//')) return false;
  let tree: UrlTree;
  try {
    tree = router.parseUrl(snapshot.internalUrl);
  } catch {
    return false;
  }
  const controls = tree.queryParams;
  // Primary route filters share the top-level query with window controls. Validate
  // them with the same bounded allowlist used for window query state.
  if (!validRouteQuery(controls)) return false;
  if (controls['osPrimary'] !== undefined && controls['osPrimary'] !== '1') return false;
  if (controls['osMin'] !== undefined && typeof controls['osMin'] !== 'string') return false;
  if (typeof controls['osActive'] !== 'string' && controls['osActive'] !== undefined) return false;
  if (typeof controls['osMax'] !== 'string' && controls['osMax'] !== undefined) return false;
  const minimized = typeof controls['osMin'] === 'string' ? controls['osMin'].split(',') : [];
  if (minimized.some((slot) => !WINDOW_SLOTS.includes(slot as WindowSlot))) return false;
  for (const key of ['osActive', 'osMax'] as const) {
    const slot = controls[key];
    if (slot !== undefined && !WINDOW_SLOTS.includes(slot as WindowSlot)) return false;
  }
  const outlets = Object.keys(tree.root.children).filter((key) => key !== 'primary');
  if (outlets.some((slot) => !WINDOW_SLOTS.includes(slot as WindowSlot))) return false;
  const found: WindowSlot[] = [];
  for (const slot of WINDOW_SLOTS) {
    const outlet = findOutlet(tree.root, slot);
    if (!outlet) continue;
    found.push(slot);
    const path = flattenPath(outlet).filter(Boolean).slice(1).join('/');
    const definition = windowRouteByPath(windowRouteDefinitions(router.config).map((item) => item.route), path);
    if (!definition || definition.module && !hasModule(definition.module)) return false;
    const query = decodeWindowQuery(convertToParamMap(windowQuery(outlet)));
    if (query.keys.some((key) => CREDENTIAL_QUERY_KEY.test(key))) return false;
  }
  if (typeof controls['osActive'] === 'string' && !found.includes(controls['osActive'] as WindowSlot)) return false;
  if (typeof controls['osMax'] === 'string' && !found.includes(controls['osMax'] as WindowSlot)) return false;
  if (minimized.some((slot) => !found.includes(slot as WindowSlot))) return false;
  if (workspaceVisibleUrl(router, tree) !== snapshot.visibleUrl) return false;
  return true;
}

function flattenPath(group: UrlSegmentGroup | null): string[] {
  if (!group) return [];
  return [...group.segments.map((segment) => segment.path), ...flattenPath(group.children['primary'] ?? null)];
}

function findOutlet(group: UrlSegmentGroup, slot: WindowSlot): UrlSegmentGroup | null {
  if (Object.prototype.hasOwnProperty.call(group.children, slot)) return group.children[slot];
  for (const child of Object.values(group.children)) {
    const found = findOutlet(child, slot);
    if (found) return found;
  }
  return null;
}

function windowQuery(group: UrlSegmentGroup): Record<string, string | string[]> {
  const entries: Record<string, string | string[]> = {};
  const visit = (current: UrlSegmentGroup): void => {
    for (const segment of current.segments) {
      for (const [key, value] of Object.entries(segment.parameters)) {
        if (key.startsWith('wq_')) entries[key] = value;
      }
    }
    for (const child of Object.values(current.children)) visit(child);
  };
  visit(group);
  return entries;
}

function validRouteQuery(query: Record<string, unknown>): boolean {
  const entries = Object.entries(query).filter(([key]) => !CONTROL_KEYS.has(key));
  if (entries.length > 32) return false;
  let totalLength = 0;
  for (const [key, raw] of entries) {
    const values = Array.isArray(raw) ? raw : [raw];
    if (!/^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(key) || CREDENTIAL_QUERY_KEY.test(key)
      || values.length === 0 || values.some((value) => typeof value !== 'string' || value.length > 1024)) return false;
    totalLength += key.length + values.reduce((sum, value) => sum + (typeof value === 'string' ? value.length : 0), 0);
    if (totalLength > 8192) return false;
  }
  return true;
}
