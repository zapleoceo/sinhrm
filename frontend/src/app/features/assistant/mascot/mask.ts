/**
 * Clipping of the figure for in-scene exits and entrances (world coordinates). He disappears INTO a door, a hole or
 * a portal instead of sliding past the viewport edge:
 * - `rect` / `ellipse` — only the inside stays visible (a doorway, elevator doors, a portal, the eraser line);
 * - `hole` — the part below the front rim of a hole in the floor is hidden (hatch, trapdoor), everything else stays.
 */
export type FigureMask =
  | { kind: 'rect'; x0: number; y0: number; x1: number; y1: number }
  | { kind: 'ellipse'; cx: number; cy: number; rx: number; ry: number }
  | { kind: 'hole'; cx: number; cy: number; rx: number; ry: number };

/** Far enough to cover any viewport. */
const FAR = 100000;
const EMPTY = 'M 0 0 Z';
const r1 = (v: number): string => (Math.round(v * 10) / 10).toString();

/** SVG path of the visible region (clip-rule evenodd). */
export function maskPath(m: FigureMask): string {
  switch (m.kind) {
    case 'rect':
      return m.x1 - m.x0 < 0.1 || m.y1 - m.y0 < 0.1 ? EMPTY : `M ${r1(m.x0)} ${r1(m.y0)} H ${r1(m.x1)} V ${r1(m.y1)} H ${r1(m.x0)} Z`;
    case 'ellipse':
      if (m.rx < 0.1 || m.ry < 0.1) {
        return EMPTY;
      }
      return `M ${r1(m.cx - m.rx)} ${r1(m.cy)} A ${r1(m.rx)} ${r1(m.ry)} 0 1 0 ${r1(m.cx + m.rx)} ${r1(m.cy)} A ${r1(m.rx)} ${r1(m.ry)} 0 1 0 ${r1(m.cx - m.rx)} ${r1(m.cy)} Z`;
    case 'hole': {
      // Everything, minus the region under the front (lower) half of the rim (evenodd cuts it out).
      const x0 = r1(m.cx - m.rx);
      const x1 = r1(m.cx + m.rx);
      const cy = r1(m.cy);
      return `M ${-FAR} ${-FAR} H ${FAR} V ${FAR} H ${-FAR} Z M ${x0} ${cy} A ${r1(m.rx)} ${r1(m.ry)} 0 0 0 ${x1} ${cy} L ${x1} ${FAR} L ${x0} ${FAR} Z`;
    }
  }
}

/** Is the world point hidden by the mask? */
export function maskHides(m: FigureMask, x: number, y: number): boolean {
  switch (m.kind) {
    case 'rect':
      return m.x1 - m.x0 < 0.1 || m.y1 - m.y0 < 0.1 || x < m.x0 || x > m.x1 || y < m.y0 || y > m.y1;
    case 'ellipse': {
      if (m.rx < 0.1 || m.ry < 0.1) {
        return true;
      }
      const dx = (x - m.cx) / m.rx;
      const dy = (y - m.cy) / m.ry;
      return dx * dx + dy * dy > 1;
    }
    case 'hole': {
      const dx = (x - m.cx) / m.rx;
      if (dx < -1 || dx > 1) {
        return false;
      }
      return y > m.cy + m.ry * Math.sqrt(1 - dx * dx);
    }
  }
}
