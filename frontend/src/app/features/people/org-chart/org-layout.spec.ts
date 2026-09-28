import { OrgNode } from '../people.model';
import {
  CARD_H,
  CARD_W,
  centerOn,
  departmentHue,
  distinctRefs,
  filterForest,
  fitTransform,
  layoutForest,
  neighbour,
  pathTo,
  searchPeople,
  subtreeSize,
  toSvg,
  visibleNodes,
  zoomAt,
} from './org-layout';

const ref = (id: number, name: string) => ({ id, name });
const node = (
  id: number,
  name: string,
  reports: OrgNode[] = [],
  dept: number | null = null,
  branch: number | null = null,
): OrgNode => ({
  id,
  full_name: name,
  avatar_url: null,
  position: ref(1, 'Engineer'),
  department: dept === null ? null : ref(dept, `Dept ${dept}`),
  branch: branch === null ? null : ref(branch, `Branch ${branch}`),
  reports_count: reports.length,
  reports,
});

// CEO(1) → CTO(2) → [Dev A(4), Dev B(5)]; CEO → CFO(3)
const forest = (): OrgNode[] => [
  node(1, 'Olena CEO', [
    node(2, 'Taras CTO', [node(4, 'Anna Dev', [], 10, 1), node(5, 'Borys Dev', [], 10, 2)], 10, 1),
    node(3, 'Iryna CFO', [], 20, 1),
  ]),
];

describe('org chart layout', () => {
  it('counts the whole subtree as headcount', () => {
    expect(subtreeSize(forest()[0])).toBe(4);
  });

  it('lays out only open branches, top-down, parent centred above children', () => {
    const l = layoutForest(forest(), new Set([1]), 'vertical');
    expect(l.nodes.map((n) => n.node.id).sort()).toEqual([1, 2, 3]);
    const byId = new Map(l.nodes.map((n) => [n.node.id, n]));
    expect(byId.get(1)!.y).toBe(0);
    expect(byId.get(2)!.y).toBeGreaterThan(CARD_H);
    expect(byId.get(1)!.x).toBeCloseTo((byId.get(2)!.x + byId.get(3)!.x) / 2);
    expect(byId.get(2)!.headcount).toBe(2);
    expect(byId.get(2)!.open).toBe(false);
    expect(l.links.map((x) => x.to).sort()).toEqual([2, 3]);
    expect(l.links[0].d).toMatch(/^M[\d.-]+,[\d.-]+ C/);
  });

  it('switches axes for left-to-right', () => {
    const l = layoutForest(forest(), new Set([1]), 'horizontal');
    const byId = new Map(l.nodes.map((n) => [n.node.id, n]));
    expect(byId.get(1)!.x).toBe(0);
    expect(byId.get(2)!.x).toBeGreaterThan(CARD_W);
  });

  it('gives aria position within siblings', () => {
    const l = layoutForest(forest(), new Set([1, 2]), 'vertical');
    const b = l.nodes.find((n) => n.node.id === 5)!;
    expect([b.pos, b.size, b.depth]).toEqual([2, 2, 2]);
  });

  it('fits bounds into the viewport and never zooms above 1', () => {
    const small = fitTransform({ x: 0, y: 0, w: 100, h: 50 }, 1000, 500);
    expect(small.k).toBe(1);
    const big = fitTransform({ x: 0, y: 0, w: 4000, h: 1000 }, 1000, 500);
    expect(big.k).toBeLessThan(0.3);
    expect(big.x + 2000 * big.k).toBeCloseTo(500); // centred
  });

  it('zooms around a fixed point and clamps', () => {
    const t = zoomAt({ x: 0, y: 0, k: 1 }, 2, 100, 100);
    expect(t).toEqual({ k: 2, x: -100, y: -100 });
    expect(zoomAt({ x: 0, y: 0, k: 1 }, 100, 0, 0).k).toBe(2.5);
    expect(centerOn(50, 50, 200, 200, 1)).toEqual({ x: 50, y: 50, k: 1 });
  });

  it('renders only nodes inside the viewport (virtualisation)', () => {
    const many = Array.from({ length: 300 }, (_, i) => node(100 + i, `P ${i}`));
    const l = layoutForest([node(1, 'Boss', many)], new Set([1]), 'vertical');
    expect(l.nodes).toHaveLength(301);
    const shown = visibleNodes(l.nodes, { x: 0, y: 0, k: 1 }, 1200, 800, 0);
    expect(shown.length).toBeGreaterThan(0);
    expect(shown.length).toBeLessThan(20);
  });

  it('finds the path to the root and ranks search by name prefix', () => {
    expect(pathTo(forest(), 5)).toEqual([1, 2, 5]);
    expect(pathTo(forest(), 99)).toEqual([]);
    expect(searchPeople(forest(), 'dev').map((n) => n.id)).toEqual([4, 5]);
    expect(searchPeople(forest(), 'borys').map((n) => n.id)).toEqual([5]);
    expect(searchPeople(forest(), '  ')).toEqual([]);
  });

  it('filters by department/branch keeping managers for context', () => {
    const byDept = filterForest(forest(), { branchId: null, departmentId: 20 });
    expect(byDept[0].id).toBe(1);
    expect(byDept[0].reports.map((n) => n.id)).toEqual([3]);
    const byBranch = filterForest(forest(), { branchId: 2, departmentId: null });
    expect(pathTo(byBranch, 5)).toEqual([1, 2, 5]);
    expect(pathTo(byBranch, 4)).toEqual([]);
    expect(distinctRefs(forest(), 'department').map((d) => d.id)).toEqual([10, 20]);
  });

  it('colours departments stably and distinctly', () => {
    expect(departmentHue(null)).toBeNull();
    expect(departmentHue(10)).toBe(departmentHue(10));
    expect(departmentHue(10)).not.toBe(departmentHue(11));
  });

  it('moves between nodes with arrow keys', () => {
    const l = layoutForest(forest(), new Set([1, 2]), 'vertical');
    expect(neighbour(l, 1, 'ArrowDown', 'vertical')).not.toBeNull();
    expect(neighbour(l, 2, 'ArrowUp', 'vertical')).toBe(1);
    expect(neighbour(l, 2, 'ArrowRight', 'vertical')).toBe(3);
    expect(neighbour(l, 3, 'ArrowLeft', 'vertical')).toBe(2);
    expect(neighbour(l, 2, 'ArrowLeft', 'horizontal')).toBe(1);
  });

  it('exports a standalone, escaped SVG', () => {
    const f = [node(1, 'A <b>&', [node(2, 'B')])];
    const svg = toSvg(layoutForest(f, new Set([1]), 'vertical'), {
      bg: '#fff',
      card: '#fff',
      border: '#ccc',
      text: '#000',
      muted: '#666',
      link: '#ccc',
    });
    expect(svg.startsWith('<svg xmlns="http://www.w3.org/2000/svg"')).toBe(true);
    expect(svg).toContain('A &#60;b&#62;&#38;');
    expect(svg).not.toContain('<b>');
    expect((svg.match(/<path /g) ?? []).length).toBe(1);
  });
});
