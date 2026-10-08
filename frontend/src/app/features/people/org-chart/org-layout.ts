import { hierarchy, tree } from 'd3-hierarchy';
import { initials } from '../org-tree';
import { OrgNode } from '../people.model';

/** Pure layout/geometry helpers of the org chart canvas (no DOM). */

export type Orientation = 'vertical' | 'horizontal';

export const CARD_W = 232;
export const CARD_H = 84;
const GAP_SIBLING = 28;
const GAP_LEVEL = 72;

export interface LaidNode {
  node: OrgNode;
  /** Top-left corner of the card in chart space. */
  x: number;
  y: number;
  depth: number;
  parentId: number | null;
  /** People in the whole subtree below (not only visible ones). */
  headcount: number;
  hasChildren: boolean;
  open: boolean;
  /** 1-based position among siblings and sibling count (aria-posinset / aria-setsize). */
  pos: number;
  size: number;
}

interface LaidLink {
  from: number;
  to: number;
  d: string;
}

interface Bounds {
  x: number;
  y: number;
  w: number;
  h: number;
}

interface OrgLayout {
  nodes: LaidNode[];
  links: LaidLink[];
  bounds: Bounds;
}

export interface Transform {
  x: number;
  y: number;
  k: number;
}

const VIRTUAL_ROOT = -1;

/** Headcount of everyone below a node. */
export function subtreeSize(node: OrgNode): number {
  return node.reports.reduce((sum, r) => sum + 1 + subtreeSize(r), 0);
}

/**
 * Tidy tree (d3-hierarchy Reingold–Tilford) of the visible part of the forest: children of closed nodes are skipped.
 * Several roots hang off an invisible virtual root, so the whole forest is laid out as one tree.
 */
export function layoutForest(
  forest: readonly OrgNode[],
  open: ReadonlySet<number>,
  orientation: Orientation,
): OrgLayout {
  if (forest.length === 0) {
    return { nodes: [], links: [], bounds: { x: 0, y: 0, w: 0, h: 0 } };
  }
  const virtual: OrgNode = {
    id: VIRTUAL_ROOT,
    full_name: '',
    avatar_url: null,
    position: null,
    department: null,
    branch: null,
    reports_count: forest.length,
    reports: [...forest],
  };
  const root = hierarchy(virtual, (n) =>
    n.id === VIRTUAL_ROOT || open.has(n.id) ? n.reports : [],
  );
  const vertical = orientation === 'vertical';
  // nodeSize is [breadth, depth] in d3 terms.
  const laid = tree<OrgNode>()
    .nodeSize(
      vertical
        ? [CARD_W + GAP_SIBLING, CARD_H + GAP_LEVEL]
        : [CARD_H + GAP_SIBLING / 2, CARD_W + GAP_LEVEL],
    )
    .separation((a, b) => (a.parent === b.parent ? 1 : 1.15))(root);

  const nodes: LaidNode[] = [];
  const pos = new Map<number, { x: number; y: number }>();
  for (const d of laid.descendants()) {
    if (d.data.id === VIRTUAL_ROOT) {
      continue;
    }
    const depth = d.depth - 1;
    // Shift one level up so real roots sit at 0.
    const breadth = d.x;
    const level = d.y - (vertical ? CARD_H + GAP_LEVEL : CARD_W + GAP_LEVEL);
    const x = vertical ? breadth - CARD_W / 2 : level;
    const y = vertical ? level : breadth - CARD_H / 2;
    pos.set(d.data.id, { x, y });
    nodes.push({
      node: d.data,
      x,
      y,
      depth,
      parentId: d.parent && d.parent.data.id !== VIRTUAL_ROOT ? d.parent.data.id : null,
      headcount: subtreeSize(d.data),
      hasChildren: d.data.reports.length > 0,
      open: open.has(d.data.id),
      pos: (d.parent?.children?.indexOf(d) ?? 0) + 1,
      size: d.parent?.children?.length ?? 1,
    });
  }

  const links: LaidLink[] = [];
  for (const n of nodes) {
    if (n.parentId === null) {
      continue;
    }
    const p = pos.get(n.parentId)!;
    links.push({ from: n.parentId, to: n.node.id, d: connector(p, n, orientation) });
  }

  let minX = Infinity;
  let minY = Infinity;
  let maxX = -Infinity;
  let maxY = -Infinity;
  for (const n of nodes) {
    minX = Math.min(minX, n.x);
    minY = Math.min(minY, n.y);
    maxX = Math.max(maxX, n.x + CARD_W);
    maxY = Math.max(maxY, n.y + CARD_H);
  }
  return { nodes, links, bounds: { x: minX, y: minY, w: maxX - minX, h: maxY - minY } };
}

/** Smooth cubic connector from the parent's outgoing edge to the child's incoming edge. */
function connector(
  parent: { x: number; y: number },
  child: { x: number; y: number },
  orientation: Orientation,
): string {
  if (orientation === 'vertical') {
    const sx = parent.x + CARD_W / 2;
    const sy = parent.y + CARD_H;
    const tx = child.x + CARD_W / 2;
    const ty = child.y;
    const my = (sy + ty) / 2;
    return `M${sx},${sy} C${sx},${my} ${tx},${my} ${tx},${ty}`;
  }
  const sx = parent.x + CARD_W;
  const sy = parent.y + CARD_H / 2;
  const tx = child.x;
  const ty = child.y + CARD_H / 2;
  const mx = (sx + tx) / 2;
  return `M${sx},${sy} C${mx},${sy} ${mx},${ty} ${tx},${ty}`;
}

/** Transform that fits `bounds` into a viewport of w×h with padding; never zooms in beyond 1. */
export function fitTransform(bounds: Bounds, w: number, h: number, pad = 32): Transform {
  if (bounds.w === 0 || w === 0 || h === 0) {
    return { x: pad, y: pad, k: 1 };
  }
  const k = clampZoom(Math.min((w - pad * 2) / bounds.w, (h - pad * 2) / bounds.h, 1));
  return { x: (w - bounds.w * k) / 2 - bounds.x * k, y: (h - bounds.h * k) / 2 - bounds.y * k, k };
}

/** Transform that centres a chart point in the viewport keeping zoom `k`. */
export function centerOn(px: number, py: number, w: number, h: number, k: number): Transform {
  return { x: w / 2 - px * k, y: h / 2 - py * k, k };
}

const MIN_ZOOM = 0.15;
const MAX_ZOOM = 2.5;

function clampZoom(k: number): number {
  return Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, k));
}

/** Zoom by `factor` around the viewport point (cx, cy) so that point stays still. */
export function zoomAt(t: Transform, factor: number, cx: number, cy: number): Transform {
  const k = clampZoom(t.k * factor);
  const f = k / t.k;
  return { k, x: cx - (cx - t.x) * f, y: cy - (cy - t.y) * f };
}

/** Nodes whose card intersects the viewport (+margin) — only these are rendered (virtualisation). */
export function visibleNodes(
  nodes: readonly LaidNode[],
  t: Transform,
  w: number,
  h: number,
  margin = 200,
): LaidNode[] {
  const x0 = (-t.x - margin) / t.k;
  const y0 = (-t.y - margin) / t.k;
  const x1 = (w - t.x + margin) / t.k;
  const y1 = (h - t.y + margin) / t.k;
  return nodes.filter((n) => n.x + CARD_W >= x0 && n.x <= x1 && n.y + CARD_H >= y0 && n.y <= y1);
}

/** Ids from a root down to `id` (inclusive), or [] when absent. */
export function pathTo(forest: readonly OrgNode[], id: number): number[] {
  for (const n of forest) {
    if (n.id === id) {
      return [id];
    }
    const sub = pathTo(n.reports, id);
    if (sub.length > 0) {
      return [n.id, ...sub];
    }
  }
  return [];
}

export function findNode(forest: readonly OrgNode[], id: number): OrgNode | null {
  for (const n of forest) {
    if (n.id === id) {
      return n;
    }
    const hit = findNode(n.reports, id);
    if (hit) {
      return hit;
    }
  }
  return null;
}

/** Flat list of every node (depth-first). */
function flatten(forest: readonly OrgNode[]): OrgNode[] {
  return forest.flatMap((n) => [n, ...flatten(n.reports)]);
}

/** Search by name/position/department, best (prefix) matches first. */
export function searchPeople(forest: readonly OrgNode[], term: string, limit = 8): OrgNode[] {
  const q = term.trim().toLowerCase();
  if (q === '') {
    return [];
  }
  const scored: { n: OrgNode; s: number }[] = [];
  for (const n of flatten(forest)) {
    const name = n.full_name.toLowerCase();
    const hay = `${name} ${n.position?.name ?? ''} ${n.department?.name ?? ''}`.toLowerCase();
    if (!hay.includes(q)) {
      continue;
    }
    const s = name.startsWith(q) || name.includes(` ${q}`) ? 0 : name.includes(q) ? 1 : 2;
    scored.push({ n, s });
  }
  return scored
    .sort((a, b) => a.s - b.s || a.n.full_name.localeCompare(b.n.full_name))
    .slice(0, limit)
    .map((x) => x.n);
}

interface OrgFilter {
  branchId: number | null;
  departmentId: number | null;
}

/** Keeps nodes matching the branch/department filter together with their managers (context). */
export function filterForest(forest: readonly OrgNode[], f: OrgFilter): OrgNode[] {
  if (f.branchId === null && f.departmentId === null) {
    return [...forest];
  }
  const matches = (n: OrgNode): boolean =>
    (f.branchId === null || n.branch?.id === f.branchId) &&
    (f.departmentId === null || n.department?.id === f.departmentId);
  const visit = (n: OrgNode): OrgNode | null => {
    const reports = n.reports.map(visit).filter((r): r is OrgNode => r !== null);
    if (!matches(n) && reports.length === 0) {
      return null;
    }
    return { ...n, reports };
  };
  return forest.map(visit).filter((n): n is OrgNode => n !== null);
}

/** Distinct refs (branches or departments) present in the forest, sorted by name. */
export function distinctRefs(
  forest: readonly OrgNode[],
  key: 'branch' | 'department',
): { id: number; name: string }[] {
  const map = new Map<number, string>();
  for (const n of flatten(forest)) {
    const ref = n[key];
    if (ref) {
      map.set(ref.id, ref.name);
    }
  }
  return [...map].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name));
}

/** Stable, well-spread hue per department (golden angle), same in light and dark themes. */
export function departmentHue(id: number | null | undefined): number | null {
  if (id === null || id === undefined) {
    return null;
  }
  return Math.round((id * 137.508 + 200) % 360);
}

/** Next node for arrow-key navigation over the laid-out (visible) tree. */
export function neighbour(
  layout: OrgLayout,
  id: number,
  key: 'ArrowUp' | 'ArrowDown' | 'ArrowLeft' | 'ArrowRight',
  orientation: Orientation,
): number | null {
  const byId = new Map(layout.nodes.map((n) => [n.node.id, n]));
  const cur = byId.get(id);
  if (!cur) {
    return null;
  }
  // Map keys to tree moves: toParent / toChild / prev / next sibling on the same level.
  const moves =
    orientation === 'vertical'
      ? { ArrowUp: 'parent', ArrowDown: 'child', ArrowLeft: 'prev', ArrowRight: 'next' }
      : { ArrowLeft: 'parent', ArrowRight: 'child', ArrowUp: 'prev', ArrowDown: 'next' };
  const move = moves[key];
  if (move === 'parent') {
    return cur.parentId;
  }
  if (move === 'child') {
    const kids = layout.nodes.filter((n) => n.parentId === id);
    return kids.length ? kids[Math.floor((kids.length - 1) / 2)].node.id : null;
  }
  const level = layout.nodes
    .filter((n) => n.depth === cur.depth)
    .sort((a, b) => (orientation === 'vertical' ? a.x - b.x : a.y - b.y));
  const i = level.findIndex((n) => n.node.id === id);
  const next = level[move === 'next' ? i + 1 : i - 1];
  return next ? next.node.id : null;
}

function esc(s: string): string {
  return s.replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);
}

interface ExportColors {
  bg: string;
  card: string;
  border: string;
  text: string;
  muted: string;
  link: string;
}

/** Self-contained SVG of the laid-out chart (for "export SVG/PNG"). */
export function toSvg(layout: OrgLayout, c: ExportColors): string {
  const pad = 24;
  const { x, y, w, h } = layout.bounds;
  const W = Math.ceil(w + pad * 2);
  const H = Math.ceil(h + pad * 2);
  const parts: string[] = [];
  parts.push(
    `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="${x - pad} ${y - pad} ${W} ${H}" font-family="Roboto, Arial, sans-serif">`,
    `<rect x="${x - pad}" y="${y - pad}" width="${W}" height="${H}" fill="${c.bg}"/>`,
  );
  for (const l of layout.links) {
    parts.push(`<path d="${l.d}" fill="none" stroke="${c.link}" stroke-width="1.5"/>`);
  }
  for (const n of layout.nodes) {
    const hue = departmentHue(n.node.department?.id);
    const accent = hue === null ? c.border : `hsl(${hue} 65% 50%)`;
    const cut = (s: string, max: number): string =>
      s.length > max ? `${s.slice(0, max - 1)}…` : s;
    parts.push(
      `<g transform="translate(${n.x},${n.y})">`,
      `<rect width="${CARD_W}" height="${CARD_H}" rx="14" fill="${c.card}" stroke="${c.border}"/>`,
      `<rect x="0" y="14" width="4" height="${CARD_H - 28}" rx="2" fill="${accent}"/>`,
      `<circle cx="36" cy="${CARD_H / 2}" r="20" fill="${accent}" fill-opacity="0.18"/>`,
      `<text x="36" y="${CARD_H / 2 + 5}" text-anchor="middle" font-size="13" font-weight="600" fill="${c.text}">${esc(initials(n.node.full_name))}</text>`,
      `<text x="66" y="30" font-size="14" font-weight="600" fill="${c.text}">${esc(cut(n.node.full_name, 22))}</text>`,
      `<text x="66" y="49" font-size="12" fill="${c.muted}">${esc(cut(n.node.position?.name ?? '', 26))}</text>`,
      `<text x="66" y="67" font-size="11" fill="${accent}">${esc(cut(n.node.department?.name ?? '', 28))}</text>`,
      n.headcount > 0
        ? `<text x="${CARD_W - 12}" y="24" text-anchor="end" font-size="11" fill="${c.muted}">${n.headcount}</text>`
        : '',
      '</g>',
    );
  }
  parts.push('</svg>');
  return parts.join('');
}
