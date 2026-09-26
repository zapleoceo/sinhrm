import { OrgNode } from './people.model';

/** Pure helpers of the org chart (collapsible tree, search). */

/** Ids of nodes that have reports, up to `depth` levels (0 = roots only) — the initially expanded set. */
export function expandedToDepth(nodes: readonly OrgNode[], depth: number): Set<number> {
  const open = new Set<number>();
  const walk = (list: readonly OrgNode[], level: number): void => {
    for (const node of list) {
      if (node.reports.length > 0 && level <= depth) {
        open.add(node.id);
        walk(node.reports, level + 1);
      }
    }
  };
  walk(nodes, 0);
  return open;
}

/** Total number of people in the forest. */
export function countNodes(nodes: readonly OrgNode[]): number {
  return nodes.reduce((sum, n) => sum + 1 + countNodes(n.reports), 0);
}

/**
 * Keeps nodes whose name/position matches the term plus their ancestors (so matches stay in context).
 * Returns the filtered forest and the ids to expand so every match is visible.
 */
export function filterTree(nodes: readonly OrgNode[], term: string): { nodes: OrgNode[]; open: Set<number> } {
  const q = term.trim().toLowerCase();
  const open = new Set<number>();
  if (q === '') {
    return { nodes: [...nodes], open };
  }
  const visit = (node: OrgNode): OrgNode | null => {
    const reports = node.reports.map(visit).filter((n): n is OrgNode => n !== null);
    const self = `${node.full_name} ${node.position?.name ?? ''}`.toLowerCase().includes(q);
    if (!self && reports.length === 0) {
      return null;
    }
    if (reports.length > 0) {
      open.add(node.id);
    }
    return { ...node, reports };
  };
  return { nodes: nodes.map(visit).filter((n): n is OrgNode => n !== null), open };
}

/** Initials for an avatar placeholder: "Ivan Petrenko" → "IP". */
export function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('');
}
