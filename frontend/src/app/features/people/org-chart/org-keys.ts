import { CARD_H, CARD_W, LaidNode, Transform } from './org-layout';

type ArrowKey = 'ArrowUp' | 'ArrowDown' | 'ArrowLeft' | 'ArrowRight';
const ARROWS: readonly string[] = ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'];

/** What a key does on a focused card (or the empty viewport). */
type OrgKeyCommand =
  | { kind: 'move'; key: ArrowKey }
  | { kind: 'select' }
  | { kind: 'toggle' }
  | { kind: 'zoom'; factor: number }
  | { kind: 'fit' };

/**
 * Keyboard map of the chart: arrows go to the neighbour card, Enter opens the person, Space expands/collapses,
 * + / = and - zoom, 0 fits. Unknown keys give null (native behaviour). `prevent` says whether the page default
 * (scroll, button press) is cancelled: only for the card commands; zoom keys keep it, as before.
 */
export function orgKeyCommand(key: string): { command: OrgKeyCommand; prevent: boolean } | null {
  if (ARROWS.includes(key)) return { command: { kind: 'move', key: key as ArrowKey }, prevent: true };
  if (key === 'Enter') return { command: { kind: 'select' }, prevent: true };
  if (key === ' ') return { command: { kind: 'toggle' }, prevent: true };
  if (key === '+' || key === '=') return { command: { kind: 'zoom', factor: 1.25 }, prevent: false };
  if (key === '-') return { command: { kind: 'zoom', factor: 0.8 }, prevent: false };
  if (key === '0') return { command: { kind: 'fit' }, prevent: false };
  return null;
}

/** True when the card at (x, y) is not fully inside the `w × h` viewport under `t` (cards outside are not rendered). */
export function outsideViewport(n: Pick<LaidNode, 'x' | 'y'>, t: Transform, w: number, h: number): boolean {
  const sx = n.x * t.k + t.x;
  const sy = n.y * t.k + t.y;
  return sx < 0 || sy < 0 || sx + CARD_W * t.k > w || sy + CARD_H * t.k > h;
}
