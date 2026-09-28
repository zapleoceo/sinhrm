import { DOODLE_DRAW, DOODLE_SHAPES, Effect, doodlePoint, effectAlpha } from './effects';
import { EyeShape, MouthShape, pupilOffset } from './face';
import { bubblePath, circlePath, jitter, noise, sketchCircle, smoothPath, starPath, taperedPath } from './ink';
import { Frame } from './mascot-engine';
import { BONES, Vec } from './skeleton';

const SVG_NS = 'http://www.w3.org/2000/svg';
/** Size of the moving box and where the hip sits inside it (the drawing overflows it freely). */
export const BOX = { w: 240, h: 240, hipX: 120, hipY: 150 } as const;
const PATH_POOL = 16;
const TEXT_POOL = 4;
const f = (n: number): string => (Math.round(n * 100) / 100).toString();

type LimbKey = 'lArm' | 'rArm' | 'lLeg' | 'rLeg' | 'torso' | 'lFoot' | 'rFoot';

/**
 * Writes a Frame into SVG/DOM directly (outside Angular change detection): ink body, face, props, effects,
 * contact shadow and the speech bubble. Only changed attributes are written, no layout reads per frame
 * (the bubble is measured once per text), no SVG filters; position is a compositor transform on the box.
 */
export class MascotRenderer {
  private readonly svg: SVGSVGElement;
  private readonly body: SVGGElement;
  private readonly limbs: Record<LimbKey, SVGPathElement>;
  private readonly headPath: SVGPathElement;
  private readonly faceGroup: SVGGElement;
  private readonly eyeWhites: SVGPathElement[];
  private readonly eyeFills: SVGPathElement[];
  private readonly eyeStrokes: SVGPathElement[];
  private readonly brows: SVGPathElement[];
  private readonly mouth: SVGPathElement;
  private readonly shadow: SVGEllipseElement;
  private readonly propRope: SVGPathElement;
  private readonly propBook: SVGPathElement;
  private readonly propBalls: SVGPathElement;
  private readonly thought: SVGPathElement;
  private readonly waves: SVGPathElement;
  private readonly paths: SVGPathElement[] = [];
  private readonly texts: SVGTextElement[] = [];
  private readonly bubble: HTMLDivElement;
  private readonly bubbleOutline: SVGPathElement;
  private readonly bubbleSvg: SVGSVGElement;
  private readonly typed: HTMLSpanElement;
  private readonly rest: HTMLSpanElement;
  /** Hit area for grabbing/clicking: a thick invisible stroke along the whole body (grab him wherever you click). */
  readonly hit: SVGPathElement;
  private readonly cache = new WeakMap<Element, Map<string, string>>();
  private bubbleText = '';
  private bubbleSize = { w: 0, h: 0 };
  private bubbleSide: 'left' | 'right' = 'right';

  constructor(
    private readonly host: HTMLElement,
    private readonly doc: Document,
  ) {
    this.svg = this.el('svg', {
      class: 'mascot-svg',
      width: String(BOX.w),
      height: String(BOX.h),
      viewBox: `0 0 ${BOX.w} ${BOX.h}`,
      overflow: 'visible',
      'aria-hidden': 'true',
      focusable: 'false',
    });
    this.shadow = this.el('ellipse', { class: 'shadow', rx: '20', ry: '3.2' }, this.svg);
    const props = this.el('g', { class: 'props' }, this.svg);
    this.propRope = this.el('path', { class: 'stroke thin' }, props);
    this.propBook = this.el('path', { class: 'stroke thin' }, props);
    this.propBalls = this.el('path', { class: 'fill' }, props);

    this.body = this.el('g', { class: 'body' }, this.svg);
    const far = this.el('g', { class: 'far' }, this.body);
    this.limbs = {
      lArm: this.el('path', { class: 'fill' }, far),
      lLeg: this.el('path', { class: 'fill' }, far),
      lFoot: this.el('path', { class: 'fill' }, far),
      torso: this.el('path', { class: 'fill' }, this.body),
      rLeg: this.el('path', { class: 'fill' }, this.body),
      rFoot: this.el('path', { class: 'fill' }, this.body),
      rArm: this.el('path', { class: 'fill' }, this.body),
    };
    this.headPath = this.el('path', { class: 'head' }, this.body);
    this.faceGroup = this.el('g', { class: 'face' }, this.body);
    this.eyeWhites = [0, 1].map(() => this.el('path', { class: 'eye-white' }, this.faceGroup));
    this.eyeFills = [0, 1].map(() => this.el('path', { class: 'fill' }, this.faceGroup));
    this.eyeStrokes = [0, 1].map(() => this.el('path', { class: 'stroke face-line' }, this.faceGroup));
    this.brows = [0, 1].map(() => this.el('path', { class: 'stroke face-line' }, this.faceGroup));
    this.mouth = this.el('path', { class: 'stroke face-line' }, this.faceGroup);
    // The near arm draws over the face (hand on the chin while thinking).
    this.body.appendChild(this.limbs.rArm);

    this.thought = this.el('path', { class: 'fill' }, this.svg);
    this.waves = this.el('path', { class: 'stroke thin' }, this.svg);
    const fx = this.el('g', { class: 'fx' }, this.svg);
    for (let i = 0; i < PATH_POOL; i++) {
      this.paths.push(this.el('path', { visibility: 'hidden' }, fx));
    }
    for (let i = 0; i < TEXT_POOL; i++) {
      this.texts.push(this.el('text', { visibility: 'hidden', class: 'fx-text' }, fx));
    }
    this.hit = this.el('path', { class: 'hit' }, this.svg);
    host.appendChild(this.svg);

    this.bubble = doc.createElement('div');
    this.bubble.className = 'mascot-bubble';
    this.bubble.setAttribute('aria-hidden', 'true');
    this.bubbleSvg = this.el('svg', { class: 'bubble-svg', overflow: 'visible' });
    this.bubbleOutline = this.el('path', { class: 'bubble-outline' }, this.bubbleSvg);
    this.bubble.appendChild(this.bubbleSvg);
    const text = doc.createElement('span');
    text.className = 'bubble-text';
    this.typed = doc.createElement('span');
    this.rest = doc.createElement('span');
    this.rest.className = 'bubble-rest';
    text.append(this.typed, this.rest);
    this.bubble.appendChild(text);
    this.bubble.style.display = 'none';
    host.appendChild(this.bubble);
  }

  destroy(): void {
    this.svg.remove();
    this.bubble.remove();
  }

  /** Draws a frame. `viewport` (cached by the caller) decides the bubble side; `still` turns the line boil off. */
  render(frame: Frame, viewport: { width: number; height: number }, still: boolean): void {
    const p = frame.pose;
    const origin = { x: p.x - BOX.hipX, y: p.y - BOX.hipY };
    this.host.style.transform = `translate3d(${f(origin.x)}px, ${f(origin.y)}px, 0)`;
    const boil = still ? 0 : 0.8;

    const j = frame.joints;
    const m = frame.morph;
    const s = frame.scale;
    const headL: Vec = { x: BOX.hipX + j.head.x, y: BOX.hipY + j.head.y };
    const toLocal = (q: Vec, i: number): Vec => {
      const jt = boil ? jitter(frame.boilSeed, i, boil * (1 - m)) : { x: 0, y: 0 };
      const x = BOX.hipX + q.x + jt.x;
      const y = BOX.hipY + q.y + jt.y;
      // Morph pulls every joint into the head; scale pops the whole drawing around the head.
      const cx = x + (headL.x - x) * m;
      const cy = y + (headL.y - y) * m;
      return { x: headL.x + (cx - headL.x) * s, y: headL.y + (cy - headL.y) * s };
    };
    const thin = 1 - m * 0.75;
    const w = (list: number[]): number[] => list.map((v) => v * thin * s);
    const hip = toLocal(j.hip, 0);
    const neck = toLocal(j.neck, 1);
    const sh = toLocal(j.shoulder, 2);
    const show = m < 0.98;
    this.set(this.limbs.torso, 'd', show ? taperedPath([hip, { x: (hip.x + neck.x) / 2, y: (hip.y + neck.y) / 2 }, neck], w([3.9, 3.5, 3.1]), 0.6) : '');
    this.set(this.limbs.lArm, 'd', show ? taperedPath([sh, toLocal(j.lElbow, 3), toLocal(j.lHand, 4)], w([3.2, 2.8, 1.5])) : '');
    this.set(this.limbs.rArm, 'd', show ? taperedPath([sh, toLocal(j.rElbow, 5), toLocal(j.rHand, 6)], w([3.3, 2.9, 1.5])) : '');
    const lFoot = toLocal(j.lFoot, 8);
    const rFoot = toLocal(j.rFoot, 10);
    this.set(this.limbs.lLeg, 'd', show ? taperedPath([hip, toLocal(j.lKnee, 7), lFoot], w([3.8, 3.1, 1.9]), 0.4) : '');
    this.set(this.limbs.rLeg, 'd', show ? taperedPath([hip, toLocal(j.rKnee, 9), rFoot], w([3.9, 3.2, 1.9]), 0.4) : '');
    const footDir = p.facing * Math.cos(p.rot);
    const footLen = 5.5 * thin;
    this.set(this.limbs.lFoot, 'd', show ? taperedPath([lFoot, { x: lFoot.x + footDir * footLen, y: lFoot.y + 0.4 }], w([2.2, 1.2]), 0.6) : '');
    this.set(this.limbs.rFoot, 'd', show ? taperedPath([rFoot, { x: rFoot.x + footDir * footLen, y: rFoot.y + 0.4 }], w([2.3, 1.2]), 0.6) : '');

    const headR = (BONES.headR + (15 - BONES.headR) * m) * s;
    const headC = { x: headL.x, y: headL.y };
    this.set(this.headPath, 'd', sketchCircle(headC.x, headC.y, headR, boil ? frame.boilSeed : 0, boil ? 0.55 : 0.3));
    this.set(this.headPath, 'stroke-width', f(2.5 * (1 + m * 0.15)));

    this.renderFace(frame, headC, headR, origin);
    this.renderShadow(frame, origin);
    this.renderProps(frame, origin);
    this.renderThought(frame, headC);
    this.renderWaves(frame, headC, headR);
    this.renderEffects(frame, origin, headC);
    this.renderBubble(frame, headC, origin, viewport);
    if (m > 0.5) {
      this.set(this.hit, 'd', `M ${f(headC.x)} ${f(headC.y)} l 0.01 0`);
      this.set(this.hit, 'stroke-width', '40');
    } else {
      const L = (q: Vec): string => `${f(BOX.hipX + q.x)} ${f(BOX.hipY + q.y)}`;
      this.set(
        this.hit,
        'd',
        `M ${L(j.head)} L ${L(j.neck)} L ${L(j.hip)} M ${L(j.shoulder)} L ${L(j.lElbow)} L ${L(j.lHand)} M ${L(j.shoulder)} L ${L(j.rElbow)} L ${L(j.rHand)} M ${L(j.hip)} L ${L(j.lKnee)} L ${L(j.lFoot)} M ${L(j.hip)} L ${L(j.rKnee)} L ${L(j.rFoot)}`,
      );
      this.set(this.hit, 'stroke-width', '26');
    }
  }

  /* ───────────── face ───────────── */

  private renderFace(frame: Frame, head: Vec, r: number, origin: Vec): void {
    const m = frame.morph;
    const j = frame.joints;
    const facing = j.facing;
    const rot = j.headAngle * (1 - m);
    const sc = (r / BONES.headR) * (1 - m * 0.25);
    this.set(this.faceGroup, 'transform', `translate(${f(head.x)} ${f(head.y)}) rotate(${f((rot * 180) / Math.PI)}) scale(${f(sc * (m > 0.5 ? 1 : facing))} ${f(sc)})`);
    const expr = frame.expr;
    const lid = Math.max(frame.blink, m > 0.5 ? frame.squint : frame.squint * 0.5);
    const eyeXs = [-1.5 + (-4.3 + 1.5) * m, 4.6 + (4.3 - 4.6) * m];
    const eyeY = -1.5 - m * 0.5;
    const orb = m > 0.5;
    const whiteR = 4.4;
    // Eye direction in face-local units.
    const cos = Math.cos(-rot);
    const sin = Math.sin(-rot);
    const toLocalDir = (d: Vec): Vec => {
      const x = d.x * cos - d.y * sin;
      const y = d.x * sin + d.y * cos;
      return { x: orb ? x : x * facing, y };
    };
    for (let i = 0; i < 2; i++) {
      const ex = eyeXs[i];
      let look: Vec;
      if (orb && frame.pointer) {
        const eyeWorld = { x: origin.x + head.x + ex * sc, y: origin.y + head.y + eyeY * sc };
        const off = pupilOffset(eyeWorld, frame.pointer, (whiteR - 2 - 0.4) * sc, 90);
        look = { x: off.x / sc, y: off.y / sc };
      } else {
        const d = toLocalDir(frame.eyeDir);
        const max = orb ? whiteR - 2.4 : 1.3;
        look = { x: d.x * max, y: d.y * max };
      }
      const shape: EyeShape = expr.eyes;
      const open = 1 - lid;
      this.set(this.eyeWhites[i], 'd', orb ? circlePath(ex, eyeY, whiteR) : '');
      this.set(this.eyeWhites[i], 'transform', orb ? `translate(0 ${f(eyeY * (1 - open))}) scale(1 ${f(Math.max(0.12, open))})` : '');
      const fillShape = shape === 'dot' || shape === 'wide';
      const pr = orb ? (shape === 'wide' ? 2.3 : 2) : shape === 'wide' ? 2.3 : 1.75;
      const eyeOpen = open > 0.25;
      this.set(this.eyeFills[i], 'd', fillShape && eyeOpen ? circlePath(ex + look.x, eyeY + look.y, pr * (orb ? 1 : Math.max(0.3, open))) : '');
      this.set(this.eyeStrokes[i], 'd', !fillShape ? eyeStroke(shape, ex, eyeY, i) : !eyeOpen ? `M ${f(ex - 2)} ${f(eyeY + 0.3)} L ${f(ex + 2)} ${f(eyeY + 0.3)}` : '');
    }
    const b = expr.brows;
    for (let i = 0; i < 2; i++) {
      const ex = eyeXs[i];
      const inner = i === 0 ? 1 : -1;
      const y = eyeY - 4.6 - b * 1.4 - (orb ? 1 : 0);
      const tilt = b * 1.2;
      const x1 = ex - 1.8;
      const x2 = ex + 1.8;
      const y1 = y + (inner < 0 ? -tilt : tilt) * 0.5;
      const y2 = y - (inner < 0 ? -tilt : tilt) * 0.5;
      this.set(this.brows[i], 'd', `M ${f(x1)} ${f(y1)} L ${f(x2)} ${f(y2)}`);
    }
    this.set(this.mouth, 'd', mouthPath(expr.mouth, expr.talk, orb ? 0 : 1.8, orb ? 5.8 : 5.2));
  }

  /* ───────────── shadow, props, thought dots ───────────── */

  private renderShadow(frame: Frame, origin: Vec): void {
    const s = frame.shadow;
    if (!s) {
      this.set(this.shadow, 'visibility', 'hidden');
      return;
    }
    this.set(this.shadow, 'visibility', 'visible');
    this.set(this.shadow, 'cx', f(s.x - origin.x));
    this.set(this.shadow, 'cy', f(s.y - origin.y));
    this.set(this.shadow, 'rx', f(s.rx));
    this.set(this.shadow, 'ry', f(s.rx * 0.16));
    this.set(this.shadow, 'opacity', f(s.opacity));
  }

  private renderProps(frame: Frame, origin: Vec): void {
    const pr = frame.props;
    const j = frame.joints;
    const L = (q: Vec): Vec => ({ x: q.x - origin.x, y: q.y - origin.y });
    if (pr.rope) {
      const hand = { x: BOX.hipX + (j.lHand.x + j.rHand.x) / 2, y: BOX.hipY + (j.lHand.y + j.rHand.y) / 2 };
      const a = L(pr.rope);
      const mid = { x: (a.x + hand.x) / 2 + 6, y: (a.y + hand.y) / 2 };
      this.set(this.propRope, 'd', smoothPath([a, mid, hand]));
    } else {
      this.set(this.propRope, 'd', '');
    }
    if (pr.book) {
      const c = L(pr.book);
      const flip = pr.book.page;
      const lift = Math.sin(flip * Math.PI) * 5;
      const px = c.x + 6 - flip * 12;
      this.set(
        this.propBook,
        'd',
        `M ${f(c.x - 7)} ${f(c.y - 4)} Q ${f(c.x - 3)} ${f(c.y - 6)} ${f(c.x)} ${f(c.y - 4)} Q ${f(c.x + 3)} ${f(c.y - 6)} ${f(c.x + 7)} ${f(c.y - 4)} L ${f(c.x + 7)} ${f(c.y + 4)} Q ${f(c.x + 3)} ${f(c.y + 2)} ${f(c.x)} ${f(c.y + 4)} Q ${f(c.x - 3)} ${f(c.y + 2)} ${f(c.x - 7)} ${f(c.y + 4)} Z M ${f(c.x)} ${f(c.y - 4)} L ${f(c.x)} ${f(c.y + 4)}` +
          (flip > 0 && flip < 1 ? ` M ${f(c.x)} ${f(c.y - 4)} Q ${f((c.x + px) / 2)} ${f(c.y - 5 - lift)} ${f(px)} ${f(c.y - 4 - lift)}` : ''),
      );
    } else {
      this.set(this.propBook, 'd', '');
    }
    this.set(this.propBalls, 'd', pr.balls ? pr.balls.map((b) => circlePath(b.x - origin.x, b.y - origin.y, 2.6)).join(' ') : '');
  }

  private renderThought(frame: Frame, head: Vec): void {
    if (!frame.thinking) {
      this.set(this.thought, 'd', '');
      return;
    }
    const dir = frame.morph > 0.5 ? 1 : frame.joints.facing;
    const bx = head.x + dir * 12;
    const by = head.y - 22;
    const t = frame.time;
    const dots = [0, 1, 2].map((i) => circlePath(bx + dir * i * 6, by - 2.5 * Math.max(0, Math.sin(t * 6 - i * 0.9)), 1.7)).join(' ');
    const trail = `${circlePath(head.x + dir * 8, head.y - 13, 0.9)} ${circlePath(head.x + dir * 10, head.y - 17, 1.2)}`;
    this.set(this.thought, 'd', `${dots} ${trail}`);
  }

  /** Sound waves at the ear while listening: arcs that grow with the voice level. */
  private renderWaves(frame: Frame, head: Vec, r: number): void {
    const level = frame.listening;
    if (level === null) {
      this.set(this.waves, 'd', '');
      return;
    }
    const dir = frame.morph > 0.5 ? 1 : -frame.joints.facing;
    const cx = head.x + dir * (r + 3);
    let d = '';
    for (let i = 0; i < 3; i++) {
      const rr = 3 + i * 3.2 + level * 4 * (i + 1) * 0.5;
      const a = 0.8;
      const x1 = cx + dir * Math.cos(a) * rr;
      const x2 = x1;
      const y1 = head.y - Math.sin(a) * rr;
      const y2 = head.y + Math.sin(a) * rr;
      d += `M ${f(x1)} ${f(y1)} Q ${f(cx + dir * rr * 1.25)} ${f(head.y)} ${f(x2)} ${f(y2)} `;
    }
    this.set(this.waves, 'd', d);
    this.set(this.waves, 'opacity', f(0.45 + level * 0.55));
  }

  /* ───────────── effects ───────────── */

  private renderEffects(frame: Frame, origin: Vec, head: Vec): void {
    let pi = 0;
    let ti = 0;
    const cap = PATH_POOL;
    for (const e of frame.effects) {
      if (e.kind === 'zz' || e.kind === 'bang') {
        if (ti < TEXT_POOL) {
          this.drawText(this.texts[ti++], e, origin, head);
        }
        continue;
      }
      if (pi < cap) {
        this.drawEffect(this.paths[pi++], e, origin, head);
      }
    }
    for (; pi < PATH_POOL; pi++) {
      this.set(this.paths[pi], 'visibility', 'hidden');
    }
    for (; ti < TEXT_POOL; ti++) {
      this.set(this.texts[ti], 'visibility', 'hidden');
    }
  }

  private drawEffect(el: SVGPathElement, e: Effect, origin: Vec, head: Vec): void {
    const a = effectAlpha(e);
    const x = e.attached ? head.x + e.x : e.x - origin.x;
    const y = e.attached ? head.y + e.y : e.y - origin.y;
    const p = e.age / e.life;
    let d = '';
    let cls = 'stroke fx-line';
    let dash: string | null = null;
    switch (e.kind) {
      case 'puff':
        d = circlePath(x, y, e.size * (0.5 + 0.9 * p));
        break;
      case 'spark':
        d = starPath(x, y, e.size * (1 - 0.4 * p), e.size * 0.35, 4, e.rot);
        cls = 'fill';
        break;
      case 'streak':
        d = `M ${f(x)} ${f(y)} L ${f(x - e.vx * 0.045 * e.size)} ${f(y - e.vy * 0.045 * e.size)}`;
        break;
      case 'drop':
        d = `M ${f(x)} ${f(y - 3.2)} Q ${f(x + 2.6)} ${f(y + 1)} ${f(x)} ${f(y + 2.4)} Q ${f(x - 2.6)} ${f(y + 1)} ${f(x)} ${f(y - 3.2)} Z`;
        break;
      case 'star':
        d = starPath(head.x + Math.cos(e.rot) * 14, head.y - 15 + Math.sin(e.rot) * 4, e.size, e.size * 0.45, 5, e.rot);
        cls = 'fill';
        break;
      case 'ring':
        d = circlePath(x, y, e.size);
        break;
      case 'doodle': {
        const shape = DOODLE_SHAPES[e.data] ?? 'heart';
        const pts: Vec[] = [];
        for (let i = 0; i <= 32; i++) {
          const q = doodlePoint(shape, i / 32);
          pts.push({ x: x + q.x * e.size, y: y + q.y * e.size });
        }
        d = smoothPath(pts);
        dash = f(1 - Math.min(1, e.age / DOODLE_DRAW));
        break;
      }
      case 'arrow': {
        // Hand-drawn arrow: slightly bowed shaft + two-stroke head, shrinking as it flies.
        const sp = Math.hypot(e.vx, e.vy) || 1;
        const ux = e.vx / sp;
        const uy = e.vy / sp;
        const len = (9 + 9 * e.size) * (1 - 0.55 * p);
        const tx = x - ux * len;
        const ty = y - uy * len;
        const bow = noise(e.rot, 1) * 1.6;
        const mx = (x + tx) / 2 - uy * bow;
        const my = (y + ty) / 2 + ux * bow;
        const hl = 3 + 2.2 * e.size * (1 - 0.5 * p);
        const a = 0.55;
        const hx1 = x - (ux * Math.cos(a) - uy * Math.sin(a)) * hl;
        const hy1 = y - (uy * Math.cos(a) + ux * Math.sin(a)) * hl;
        const hx2 = x - (ux * Math.cos(-a) - uy * Math.sin(-a)) * hl;
        const hy2 = y - (uy * Math.cos(-a) + ux * Math.sin(-a)) * hl;
        d = `M ${f(tx)} ${f(ty)} Q ${f(mx)} ${f(my)} ${f(x)} ${f(y)} M ${f(hx1)} ${f(hy1)} L ${f(x)} ${f(y)} L ${f(hx2)} ${f(hy2)}`;
        break;
      }
      case 'banana':
        d = `M ${f(x - 7)} ${f(y - 1)} Q ${f(x)} ${f(y - 7)} ${f(x + 7)} ${f(y - 1)} M ${f(x - 3)} ${f(y - 3)} L ${f(x - 8)} ${f(y - 6)} M ${f(x + 3)} ${f(y - 3)} L ${f(x + 7)} ${f(y - 7)} M ${f(x - 7)} ${f(y - 1)} L ${f(x + 7)} ${f(y - 1)}`;
        break;
      case 'rope': {
        const ex = e.x + Math.sin(e.angle) * e.data - origin.x;
        const ey = e.y + Math.cos(e.angle) * e.data - origin.y;
        const ax = e.x - origin.x;
        const ay = e.y - origin.y;
        d = smoothPath([{ x: ax, y: ay }, { x: (ax + ex) / 2 - 5, y: (ay + ey) / 2 }, { x: ex, y: ey }]);
        break;
      }
      default:
        break;
    }
    this.set(el, 'visibility', 'visible');
    this.set(el, 'class', cls);
    this.set(el, 'd', d);
    this.set(el, 'opacity', f(e.kind === 'doodle' ? Math.min(1, (e.life - e.age) / 0.7) : a));
    this.set(el, 'pathLength', dash !== null ? '1' : '');
    this.set(el, 'stroke-dasharray', dash !== null ? '1 1' : '');
    this.set(el, 'stroke-dashoffset', dash ?? '');
  }

  private drawText(el: SVGTextElement, e: Effect, origin: Vec, head: Vec): void {
    const x = e.attached ? head.x + e.x : e.x - origin.x;
    const y = e.attached ? head.y + e.y : e.y - origin.y;
    const pop = e.kind === 'bang' ? 1 + 0.6 * Math.max(0, 1 - e.age / 0.15) : e.size;
    this.set(el, 'visibility', 'visible');
    if (el.textContent !== (e.kind === 'bang' ? '!' : 'z')) {
      el.textContent = e.kind === 'bang' ? '!' : 'z';
    }
    this.set(el, 'transform', `translate(${f(x)} ${f(y)}) rotate(${f(e.kind === 'zz' ? Math.sin(e.age * 3 + e.rot) * 15 : 8)}) scale(${f(pop)})`);
    this.set(el, 'opacity', f(effectAlpha(e)));
    this.set(el, 'font-size', e.kind === 'bang' ? '17' : '11');
  }

  /* ───────────── speech bubble ───────────── */

  private renderBubble(frame: Frame, head: Vec, origin: Vec, viewport: { width: number; height: number }): void {
    const b = frame.bubble;
    if (!b) {
      if (this.bubbleText) {
        this.bubbleText = '';
        this.bubble.style.display = 'none';
      }
      return;
    }
    if (b.text !== this.bubbleText) {
      this.bubbleText = b.text;
      this.bubble.style.display = 'block';
      this.typed.textContent = '';
      this.rest.textContent = b.text;
      this.bubbleSize = { w: this.bubble.offsetWidth || 180, h: this.bubble.offsetHeight || 40 };
      this.bubbleSide = origin.x + head.x > viewport.width - this.bubbleSize.w - 40 ? 'left' : 'right';
      this.bubbleSvg.setAttribute('width', String(this.bubbleSize.w));
      this.bubbleSvg.setAttribute('height', String(this.bubbleSize.h));
      this.bubbleOutline.setAttribute('d', bubblePath(this.bubbleSize.w, this.bubbleSize.h, this.bubbleSide === 'right' ? 'left' : 'right', b.text.length));
    }
    if (this.typed.textContent?.length !== b.chars) {
      this.typed.textContent = b.text.slice(0, b.chars);
      this.rest.textContent = b.text.slice(b.chars);
    }
    const { w, h } = this.bubbleSize;
    let bx = this.bubbleSide === 'right' ? head.x + 2 : head.x - w - 2;
    let by = head.y - 30 - h;
    if (origin.y + by < 8) {
      // No room above (e.g. the chat panel reaches the top edge): beside him, just below the head, tail up.
      bx = this.bubbleSide === 'right' ? head.x + 18 : head.x - w - 18;
      by = head.y - 6;
    }
    // Keep it on screen.
    bx = Math.min(Math.max(bx, 8 - origin.x), viewport.width - 8 - w - origin.x);
    by = Math.max(by, 8 - origin.y);
    this.bubble.style.transform = `translate(${f(bx)}px, ${f(by)}px)`;
  }

  /* ───────────── utilities ───────────── */

  private set(el: Element, name: string, value: string): void {
    let map = this.cache.get(el);
    if (!map) {
      map = new Map();
      this.cache.set(el, map);
    }
    if (map.get(name) === value) {
      return;
    }
    map.set(name, value);
    if (value === '') {
      el.removeAttribute(name);
    } else {
      el.setAttribute(name, value);
    }
  }

  private el<K extends keyof SVGElementTagNameMap>(tag: K, attrs: Record<string, string>, parent?: Element): SVGElementTagNameMap[K] {
    const node = this.doc.createElementNS(SVG_NS, tag);
    for (const [k, v] of Object.entries(attrs)) {
      node.setAttribute(k, v);
    }
    parent?.appendChild(node);
    return node;
  }
}

function eyeStroke(shape: EyeShape, x: number, y: number, i: number): string {
  switch (shape) {
    case 'happy':
      return `M ${f(x - 2.2)} ${f(y + 0.9)} Q ${f(x)} ${f(y - 2)} ${f(x + 2.2)} ${f(y + 0.9)}`;
    case 'sleepy':
      return `M ${f(x - 2.1)} ${f(y + 0.2)} L ${f(x + 2.1)} ${f(y + 0.6)}`;
    case 'closed':
      return `M ${f(x - 2.2)} ${f(y)} Q ${f(x)} ${f(y + 1.6)} ${f(x + 2.2)} ${f(y)}`;
    case 'x':
      return `M ${f(x - 1.8)} ${f(y - 1.8)} L ${f(x + 1.8)} ${f(y + 1.8)} M ${f(x + 1.8)} ${f(y - 1.8)} L ${f(x - 1.8)} ${f(y + 1.8)}`;
    case 'spiral': {
      const pts: Vec[] = [];
      for (let k = 0; k <= 12; k++) {
        const a = (k / 12) * Math.PI * 3.2 * (i === 0 ? 1 : -1);
        const r = 0.3 + (k / 12) * 2;
        pts.push({ x: x + Math.cos(a) * r, y: y + Math.sin(a) * r });
      }
      return smoothPath(pts);
    }
    default:
      return '';
  }
}

function mouthPath(shape: MouthShape, talk: number, x: number, y: number): string {
  switch (shape) {
    case 'grin':
      return `M ${f(x - 3.6)} ${f(y - 0.6)} Q ${f(x)} ${f(y + 3.6)} ${f(x + 3.6)} ${f(y - 0.6)} Z`;
    case 'o':
      return circlePath(x, y + 0.5, 1.5);
    case 'flat':
      return `M ${f(x - 2.4)} ${f(y + 0.4)} L ${f(x + 2.4)} ${f(y + 0.2)}`;
    case 'wobbly':
      return `M ${f(x - 3)} ${f(y + 0.6)} q 1 -1.2 2 0 t 2 0 t 2 0`;
    case 'frown':
      return `M ${f(x - 2.8)} ${f(y + 1.4)} Q ${f(x)} ${f(y - 0.8)} ${f(x + 2.8)} ${f(y + 1.4)}`;
    case 'open': {
      const hgt = 0.6 + 2.6 * Math.max(0, Math.min(1, talk));
      return `M ${f(x - 2.4)} ${f(y)} Q ${f(x)} ${f(y - 0.8)} ${f(x + 2.4)} ${f(y)} Q ${f(x)} ${f(y + hgt * 1.4)} ${f(x - 2.4)} ${f(y)} Z`;
    }
    default:
      return `M ${f(x - 3)} ${f(y)} Q ${f(x)} ${f(y + 2.4)} ${f(x + 3)} ${f(y)}`;
  }
}
