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

/* Scratch buffers (no per-frame arrays of point objects): strokes have at most MAX_PTS points. */
const MAX_PTS = 16;
const PX = new Float64Array(MAX_PTS);
const PY = new Float64Array(MAX_PTS);
const LX = new Float64Array(MAX_PTS);
const LY = new Float64Array(MAX_PTS);
const RX = new Float64Array(MAX_PTS);
const RY = new Float64Array(MAX_PTS);

/** Continues a path through the points [from..to] of (xs, ys), stepping by ±1, with rounded corners. */
function smoothXY(xs: Float64Array, ys: Float64Array, from: number, to: number): string {
  const step = to >= from ? 1 : -1;
  const count = Math.abs(to - from) + 1;
  if (count === 2) {
    return `L ${f(xs[to])} ${f(ys[to])}`;
  }
  let d = `L ${f((xs[from] + xs[from + step]) / 2)} ${f((ys[from] + ys[from + step]) / 2)} `;
  for (let i = from + step; i !== to; i += step) {
    d += `Q ${f(xs[i])} ${f(ys[i])} ${f((xs[i] + xs[i + step]) / 2)} ${f((ys[i] + ys[i + step]) / 2)} `;
  }
  return `${d}L ${f(xs[to])} ${f(ys[to])}`;
}

/**
 * A brush stroke along a polyline: filled outline whose width follows `widths` (thick at joints, thin at the tip),
 * rounded joints, round-ish caps and a small overshoot past the last point. Allocation-light (scratch buffers).
 */
export function taperedPath(points: readonly Vec[], widths: readonly number[], overshoot = 1.2): string {
  const n = Math.min(points.length, MAX_PTS);
  if (n < 2) {
    return '';
  }
  for (let i = 0; i < n; i++) {
    PX[i] = points[i].x;
    PY[i] = points[i].y;
  }
  let ex = PX[n - 1] - PX[n - 2];
  let ey = PY[n - 1] - PY[n - 2];
  let el = Math.hypot(ex, ey) || 1;
  PX[n - 1] += (ex / el) * overshoot;
  PY[n - 1] += (ey / el) * overshoot;
  let t0x = 0;
  let t0y = 0;
  let tnx = 0;
  let tny = 0;
  for (let i = 0; i < n; i++) {
    const a = Math.max(0, i - 1);
    const b = Math.min(n - 1, i + 1);
    ex = PX[b] - PX[a];
    ey = PY[b] - PY[a];
    el = Math.hypot(ex, ey) || 1;
    const tx = ex / el;
    const ty = ey / el;
    if (i === 0) {
      t0x = tx;
      t0y = ty;
    }
    if (i === n - 1) {
      tnx = tx;
      tny = ty;
    }
    const w = (widths[Math.min(i, widths.length - 1)] ?? 2) / 2;
    LX[i] = PX[i] - ty * w;
    LY[i] = PY[i] + tx * w;
    RX[i] = PX[i] + ty * w;
    RY[i] = PY[i] - tx * w;
  }
  const wEnd = (widths[Math.min(n - 1, widths.length - 1)] ?? 2) * 0.9;
  const wStart = (widths[0] ?? 2) * 0.9;
  return (
    `M ${f(LX[0])} ${f(LY[0])} ${smoothXY(LX, LY, 0, n - 1)} Q ${f(PX[n - 1] + tnx * wEnd)} ${f(PY[n - 1] + tny * wEnd)} ${f(RX[n - 1])} ${f(RY[n - 1])} ` +
    `${smoothXY(RX, RY, n - 1, 0)} Q ${f(PX[0] - t0x * wStart)} ${f(PY[0] - t0y * wStart)} ${f(LX[0])} ${f(LY[0])} Z`
  );
}

/** Open smooth stroke through points (for strokes drawn with stroke-width). */
export function smoothPath(points: readonly Vec[]): string {
  const n = points.length;
  if (n === 0) {
    return '';
  }
  if (n > MAX_PTS) {
    // Long doodles: plain polyline (rare, not per-frame heavy).
    let d = `M ${pt(points[0])}`;
    for (let i = 1; i < n; i++) {
      d += ` L ${pt(points[i])}`;
    }
    return d;
  }
  for (let i = 0; i < n; i++) {
    PX[i] = points[i].x;
    PY[i] = points[i].y;
  }
  return `M ${f(PX[0])} ${f(PY[0])} ${n > 1 ? smoothXY(PX, PY, 0, n - 1) : ''}`;
}

/** A circle drawn by hand: one loop with slightly uneven radius that overshoots its start. */
export function sketchCircle(cx: number, cy: number, r: number, seed: number, wobble = 0.5): string {
  const steps = 14;
  const start = noise(seed, 99) * 0.6 - 1.9;
  const sweep = Math.PI * 2 + 0.45;
  for (let i = 0; i <= steps; i++) {
    const a = start + (sweep * i) / steps;
    const rr = r + noise(seed, i) * wobble + (i === steps ? 0.7 : 0);
    PX[i] = cx + Math.cos(a) * rr;
    PY[i] = cy + Math.sin(a) * rr;
  }
  return `M ${f(PX[0])} ${f(PY[0])} ${smoothXY(PX, PY, 0, steps)}`;
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
