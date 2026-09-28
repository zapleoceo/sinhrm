import { Vec } from './skeleton';

/** Hand-drawn ink geometry as SVG path strings. Pure. */

/** Deterministic noise in [-1, 1] for a seed and index (the "line boil"). */
export function noise(seed: number, i: number): number {
  const s = Math.sin(seed * 127.1 + i * 311.7) * 43758.5453;
  return (s - Math.floor(s)) * 2 - 1;
}

export function jitter(seed: number, i: number, amp: number): Vec {
  return { x: noise(seed, i * 2) * amp, y: noise(seed, i * 2 + 1) * amp };
}

const f = (n: number): string => (Math.round(n * 100) / 100).toString();
const pt = (p: Vec): string => `${f(p.x)} ${f(p.y)}`;

function unit(dx: number, dy: number): Vec {
  const len = Math.hypot(dx, dy) || 1;
  return { x: dx / len, y: dy / len };
}

/**
 * A brush stroke along a polyline: filled outline whose width follows `widths` (thick at joints, thin at the tip),
 * rounded joints, round-ish caps and a small overshoot past the last point.
 */
export function taperedPath(points: readonly Vec[], widths: readonly number[], overshoot = 1.2): string {
  const n = points.length;
  if (n < 2) {
    return '';
  }
  const pts = points.map((p) => ({ ...p }));
  const endDir = unit(pts[n - 1].x - pts[n - 2].x, pts[n - 1].y - pts[n - 2].y);
  pts[n - 1] = { x: pts[n - 1].x + endDir.x * overshoot, y: pts[n - 1].y + endDir.y * overshoot };
  const tangents = pts.map((_, i) => {
    const a = pts[Math.max(0, i - 1)];
    const b = pts[Math.min(n - 1, i + 1)];
    return unit(b.x - a.x, b.y - a.y);
  });
  const left: Vec[] = [];
  const right: Vec[] = [];
  pts.forEach((p, i) => {
    const t = tangents[i];
    const w = (widths[Math.min(i, widths.length - 1)] ?? 2) / 2;
    left.push({ x: p.x - t.y * w, y: p.y + t.x * w });
    right.push({ x: p.x + t.y * w, y: p.y - t.x * w });
  });
  const wEnd = (widths[Math.min(n - 1, widths.length - 1)] ?? 2) * 0.9;
  const wStart = (widths[0] ?? 2) * 0.9;
  const tipCtrl = { x: pts[n - 1].x + tangents[n - 1].x * wEnd, y: pts[n - 1].y + tangents[n - 1].y * wEnd };
  const startCtrl = { x: pts[0].x - tangents[0].x * wStart, y: pts[0].y - tangents[0].y * wStart };
  return `M ${pt(left[0])} ${smoothThrough(left)} Q ${pt(tipCtrl)} ${pt(right[n - 1])} ${smoothThrough([...right].reverse())} Q ${pt(startCtrl)} ${pt(left[0])} Z`;
}

/** Continues a path through points with rounded corners (quadratic through midpoints), ending at the last point. */
function smoothThrough(points: readonly Vec[]): string {
  const n = points.length;
  if (n === 2) {
    return `L ${pt(points[1])}`;
  }
  let d = '';
  for (let i = 1; i < n - 1; i++) {
    const mid = { x: (points[i].x + points[i + 1].x) / 2, y: (points[i].y + points[i + 1].y) / 2 };
    d += `${i === 1 ? `L ${pt({ x: (points[0].x + points[1].x) / 2, y: (points[0].y + points[1].y) / 2 })} ` : ''}Q ${pt(points[i])} ${pt(mid)} `;
  }
  return `${d}L ${pt(points[n - 1])}`;
}

/** Open smooth stroke through points (for strokes drawn with stroke-width). */
export function smoothPath(points: readonly Vec[]): string {
  if (points.length === 0) {
    return '';
  }
  return `M ${pt(points[0])} ${points.length > 1 ? smoothThrough(points) : ''}`;
}

/** A circle drawn by hand: one loop with slightly uneven radius that overshoots its start. */
export function sketchCircle(cx: number, cy: number, r: number, seed: number, wobble = 0.5): string {
  const steps = 14;
  const start = noise(seed, 99) * 0.6 - 1.9;
  const sweep = Math.PI * 2 + 0.45;
  const points: Vec[] = [];
  for (let i = 0; i <= steps; i++) {
    const a = start + (sweep * i) / steps;
    const rr = r + noise(seed, i) * wobble + (i === steps ? 0.7 : 0);
    points.push({ x: cx + Math.cos(a) * rr, y: cy + Math.sin(a) * rr });
  }
  return smoothPath(points);
}

/** Closed circle path (for fills and pupils). */
export function circlePath(cx: number, cy: number, r: number): string {
  return `M ${f(cx - r)} ${f(cy)} a ${f(r)} ${f(r)} 0 1 0 ${f(2 * r)} 0 a ${f(r)} ${f(r)} 0 1 0 ${f(-2 * r)} 0 Z`;
}

/** Wobbly speech-bubble outline around a w×h box at (0,0) with a tail at the bottom pointing left or right. */
export function bubblePath(w: number, h: number, tail: 'left' | 'right', seed: number): string {
  const r = Math.min(12, h / 2);
  const wob = (i: number): number => noise(seed, i) * 0.9;
  const tx = tail === 'left' ? Math.min(26, w * 0.25) : Math.max(w - 26, w * 0.75);
  const tipX = tail === 'left' ? tx - 14 : tx + 14;
  const pts: string[] = [
    `M ${f(r)} ${f(wob(1))}`,
    `L ${f(w - r)} ${f(wob(2))}`,
    `Q ${f(w + wob(3))} ${f(wob(4))} ${f(w + wob(5))} ${f(r)}`,
    `L ${f(w + wob(6))} ${f(h - r)}`,
    `Q ${f(w + wob(7))} ${f(h + wob(8))} ${f(w - r)} ${f(h + wob(9))}`,
  ];
  const tailPts =
    tail === 'left'
      ? [`L ${f(tx + 6)} ${f(h + wob(10))}`, `L ${f(tipX)} ${f(h + 12)}`, `L ${f(tx - 4)} ${f(h + wob(11))}`]
      : [`L ${f(tx + 4)} ${f(h + wob(10))}`, `L ${f(tipX)} ${f(h + 12)}`, `L ${f(tx - 6)} ${f(h + wob(11))}`];
  return [
    ...pts,
    ...tailPts,
    `L ${f(r)} ${f(h + wob(12))}`,
    `Q ${f(wob(13))} ${f(h + wob(14))} ${f(wob(15))} ${f(h - r)}`,
    `L ${f(wob(16))} ${f(r)}`,
    `Q ${f(wob(17))} ${f(wob(18))} ${f(r)} ${f(wob(1))}`,
    'Z',
  ].join(' ');
}

/** Small star (n points) around a centre. */
export function starPath(cx: number, cy: number, outer: number, inner: number, points: number, rot: number): string {
  let d = '';
  for (let i = 0; i < points * 2; i++) {
    const a = rot + (i * Math.PI) / points;
    const r = i % 2 === 0 ? outer : inner;
    d += `${i === 0 ? 'M' : 'L'} ${f(cx + Math.cos(a) * r)} ${f(cy + Math.sin(a) * r)} `;
  }
  return `${d}Z`;
}
