import { ExitAction, EXITS, Stage, sitting, standAt } from './animations';
import { BrainCommand, MascotBrain, MOVE_EXIT_CHANCE, TIMING } from './brain';
import { EXIT_INFO, SCENE_ENTRANCES, SceneAction, SceneContext, createSceneClip, sceneEnd } from './exits';
import { MascotEngine } from './mascot-engine';
import { MascotRenderer } from './mascot-renderer';
import { FigureMask, maskHides, maskPath } from './mask';
import { isMove } from './moves';
import { BONES, Pose, forwardKinematics } from './skeleton';

const STAGE: Stage = { width: 1200, height: 800, ground: 798, seat: { x: 1000, y: 300 }, corner: { x: 1166, y: 766 } };
const KEYS = ['head', 'neck', 'shoulder', 'hip', 'lHand', 'rHand', 'lFoot', 'rFoot', 'lKnee', 'rKnee', 'lElbow', 'rElbow'] as const;
const env = { vx: 0, vy: 0, spin: 0, pointer: null };

function seeded(seed = 3): () => number {
  let s = seed;
  return () => {
    s = (s * 16807) % 2147483647;
    return (s - 1) / 2147483646;
  };
}

type W = Record<(typeof KEYS)[number], { x: number; y: number }>;
function world(p: Pose): W {
  const j = forwardKinematics(p);
  const out = {} as W;
  for (const k of KEYS) {
    out[k] = { x: p.x + j[k].x, y: p.y + j[k].y };
  }
  return out;
}
const jump = (a: W, b: W): number => Math.max(...KEYS.map((k) => Math.hypot(a[k].x - b[k].x, a[k].y - b[k].y)));

/** Every joint and the outline of the head are hidden by the mask (or the drawing is scaled to nothing). */
function fullyHidden(p: Pose, mask: FigureMask | undefined, scale = 1): boolean {
  if (scale < 0.01) {
    return true;
  }
  if (!mask) {
    return false;
  }
  const w = world(p);
  const pts = KEYS.map((k) => w[k]);
  const r = BONES.headR;
  pts.push({ x: w.head.x, y: w.head.y - r }, { x: w.head.x - r, y: w.head.y }, { x: w.head.x + r, y: w.head.y });
  return pts.every((q) => maskHides(mask, q.x, q.y));
}

type Sampled = ReturnType<ReturnType<typeof createSceneClip>['sample']>;

function run(action: SceneAction, c: SceneContext): { frames: Sampled[]; duration: number } {
  const clip = createSceneClip(action, c);
  const frames: Sampled[] = [];
  for (let t = 0; t <= clip.duration + 1e-9; t += 1 / 60) {
    frames.push(clip.sample(t, env));
  }
  frames.push(clip.sample(clip.duration, env));
  return { frames, duration: clip.duration };
}

function checkContinuous(name: string, frames: Sampled[]): void {
  let prev = world(frames[0].pose);
  for (let i = 1; i < frames.length; i++) {
    const f = frames[i];
    const cur = world(f.pose);
    for (const k of KEYS) {
      expect(Number.isFinite(cur[k].x) && Number.isFinite(cur[k].y), `${name} finite`).toBe(true);
    }
    expect(jump(cur, prev), `${name} jump at frame ${i}`).toBeLessThan(40);
    for (const it of f.props?.ink ?? []) {
      expect(it.d.includes('NaN'), `${name} prop`).toBe(false);
      for (const m of it.d.matchAll(/[MLH] (-?[\d.]+)/g)) {
        expect(Number(m[1]), `${name} prop on screen`).toBeGreaterThanOrEqual(-15);
        expect(Number(m[1]), `${name} prop on screen`).toBeLessThanOrEqual(STAGE.width + 15);
      }
    }
    prev = cur;
  }
}

const inkLeft = (f: Sampled): number => Math.max(0, ...(f.props?.ink ?? []).map((i) => i.alpha * (i.draw > 0.001 ? 1 : 0)));

const STARTS: { name: string; from: Pose }[] = [
  { name: 'standing →', from: standAt(STAGE, 400, 1) },
  { name: 'standing ←', from: standAt(STAGE, 700, -1) },
  { name: 'at the left edge', from: standAt(STAGE, 25, -1) },
  { name: 'at the right edge', from: standAt(STAGE, 1185, 1) },
  { name: 'peeking from off screen', from: standAt(STAGE, -36, 1, { torso: 0.95, head: -0.4 }) },
  { name: 'seated on the chat', from: sitting(1000, 300, -1, 0) },
];

describe('in-scene exits: continuous, never past the edge, he ends hidden inside the door/hole', () => {
  it.each(EXITS.map((e) => [e]))('%s', (exit: ExitAction) => {
    for (const { name, from } of STARTS) {
      for (const lite of [false, true]) {
        for (const seed of [1, 2, 5, 9]) {
          const label = `${exit} ${name}${lite ? ' lite' : ''} #${seed}`;
          const { frames, duration } = run(exit, { stage: STAGE, from, lite, rng: seeded(seed), targetX: from.x, side: 'left' });
          expect(duration, label).toBeGreaterThan(2);
          expect(duration, label).toBeLessThan(12);
          // Starts exactly at the displayed pose (the phase runner's blend).
          expect(jump(world(frames[0].pose), world(from)), `${label} start`).toBeLessThan(2);
          checkContinuous(label, frames);
          const end = frames[frames.length - 1];
          expect(fullyHidden(end.pose, end.mask, end.scale ?? 1), `${label}: no joint visible at the end`).toBe(true);
          expect(inkLeft(end), `${label}: props gone at the end`).toBeLessThan(0.02);
          // He never walks off: the hip stays inside the viewport once the clip has started.
          for (const f of frames.slice(Math.floor(frames.length / 3))) {
            expect(f.pose.x, `${label} on screen`).toBeGreaterThan(0);
            expect(f.pose.x, `${label} on screen`).toBeLessThan(STAGE.width);
          }
        }
      }
    }
  });

  it('the figure is visible while he walks up to it and hidden progressively (a clip, not a cut)', () => {
    for (const exit of EXITS) {
      const { frames } = run(exit, { stage: STAGE, from: standAt(STAGE, 500, 1), lite: false, rng: seeded(3), targetX: 500, side: 'left' });
      expect(frames[0].mask, `${exit} starts unclipped`).toBeUndefined();
      const masked = frames.filter((f) => f.mask);
      expect(masked.length, `${exit} uses a mask`).toBeGreaterThan(10);
      const partly = masked.some((f) => !fullyHidden(f.pose, f.mask, f.scale ?? 1));
      expect(partly, `${exit} is partly visible inside the mask for a while`).toBe(true);
    }
  });

  it('lite: props appear without the draw-on', () => {
    for (const exit of EXITS) {
      const { frames } = run(exit, { stage: STAGE, from: standAt(STAGE, 500, 1), lite: true, rng: seeded(3), targetX: 500, side: 'left' });
      for (const f of frames) {
        for (const it of f.props?.ink ?? []) {
          expect(it.draw, exit).toBe(1);
        }
      }
    }
  });
});

describe('mirrored entrances: out of a door, a hatch, a portal', () => {
  it.each(SCENE_ENTRANCES.map((e) => [e]))('%s', (entrance) => {
    for (const side of ['left', 'right'] as const) {
      for (const targetX of [80, 600, 1120]) {
        for (const lite of [false, true]) {
          const label = `${entrance} ${side} → ${targetX}${lite ? ' lite' : ''}`;
          const c: SceneContext = { stage: STAGE, from: standAt(STAGE, -300, 1), lite, rng: seeded(4), targetX, side };
          const { frames } = run(entrance, c);
          const first = frames[0];
          expect(fullyHidden(first.pose, first.mask, first.scale ?? 1), `${label}: hidden at the start`).toBe(true);
          checkContinuous(label, frames);
          const end = frames[frames.length - 1];
          expect(jump(world(end.pose), world(sceneEnd(entrance, c))), `${label} end`).toBeLessThan(2);
          expect(end.mask, `${label}: unclipped at the end`).toBeUndefined();
          expect(inkLeft(end), `${label}: props gone`).toBeLessThan(0.02);
        }
      }
    }
  });
});

describe('mask geometry', () => {
  it('rect/ellipse keep the inside, a hole hides what is below its front rim', () => {
    expect(maskHides({ kind: 'rect', x0: 0, y0: 0, x1: 10, y1: 10 }, 5, 5)).toBe(false);
    expect(maskHides({ kind: 'rect', x0: 0, y0: 0, x1: 10, y1: 10 }, 15, 5)).toBe(true);
    expect(maskHides({ kind: 'rect', x0: 5, y0: 0, x1: 5, y1: 10 }, 5, 5)).toBe(true);
    expect(maskHides({ kind: 'ellipse', cx: 0, cy: 0, rx: 10, ry: 10 }, 3, 3)).toBe(false);
    expect(maskHides({ kind: 'ellipse', cx: 0, cy: 0, rx: 0, ry: 0 }, 0, 0)).toBe(true);
    const hole: FigureMask = { kind: 'hole', cx: 100, cy: 50, rx: 20, ry: 5 };
    expect(maskHides(hole, 100, 60)).toBe(true);
    expect(maskHides(hole, 100, 52)).toBe(false);
    expect(maskHides(hole, 140, 90)).toBe(false);
    expect(maskPath(hole)).toContain('A 20 5 0 0 0 120 50');
    expect(maskPath({ kind: 'rect', x0: 1, y0: 1, x1: 1, y1: 9 })).toBe('M 0 0 Z');
  });
});

describe('brain: exit choice', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  function cycle(opts: { lite?: boolean; rng?: () => number } = {}): { exits: string[]; plays: string[] } {
    const commands: BrainCommand[] = [];
    const brain = new MascotBrain((c) => commands.push(c), { rng: opts.rng ?? seeded(17), width: () => 1200 });
    brain.start(true, false);
    if (opts.lite) {
      brain.setLite(true);
    }
    const plays = (): Extract<BrainCommand, { type: 'play' }>[] => commands.filter((c): c is Extract<BrainCommand, { type: 'play' }> => c.type === 'play');
    const exits: string[] = [];
    vi.advanceTimersByTime(TIMING.firstAppearance);
    for (let round = 0; round < 40; round++) {
      // Through the entrance (a peek may turn straight into an exit) into idle, then wait for the exit.
      for (let i = 0; i < 4 && brain.state === 'entering'; i++) {
        brain.clipDone(plays().at(-1)!.action);
      }
      if (brain.state === 'idle') {
        vi.advanceTimersByTime(TIMING.stay);
      }
      for (let i = 0; i < 3 && brain.state === 'exiting'; i++) {
        const last = plays().at(-1)!;
        if (isMove(last.action)) {
          // A move exit stops on screen, near a side.
          expect(last.targetX!).toBeGreaterThan(50);
          expect(last.targetX!).toBeLessThan(1150);
        } else {
          exits.push(last.action);
        }
        brain.clipDone(last.action);
      }
      expect(brain.state).toBe('offstage');
      vi.advanceTimersByTime(TIMING.maxGap + 1);
    }
    return { exits, plays: plays().map((p) => p.action) };
  }

  it('weighted random, never the same exit twice in a row, all of them get used', () => {
    const { exits } = cycle();
    expect(exits.length).toBeGreaterThanOrEqual(40);
    for (let i = 1; i < exits.length; i++) {
      expect(exits[i], `repeat at ${i}`).not.toBe(exits[i - 1]);
    }
    for (const e of EXITS) {
      expect(exits, e).toContain(e);
    }
    expect(MOVE_EXIT_CHANCE).toBeGreaterThan(0);
  });

  it('lite: only the plain door and hatch; entrances stay simple too', () => {
    const { exits, plays } = cycle({ lite: true });
    expect(new Set(exits)).toEqual(new Set(EXITS.filter((e) => EXIT_INFO[e].lite)));
    expect(plays.filter((p) => p === 'enter-portal')).toEqual([]);
  });

  it('no action ever leaves past the viewport edge any more', () => {
    const { plays } = cycle({ rng: seeded(23) });
    for (const p of plays) {
      expect(['exit-run', 'exit-jump', 'exit-slide', 'exit-wave', 'exit-peek']).not.toContain(p);
    }
  });

  it('chat opened while he is leaving: he turns back and takes a route to the panel (no straight blend)', () => {
    const commands: BrainCommand[] = [];
    const brain = new MascotBrain((c) => commands.push(c), { rng: () => 0.9, width: () => 1200 });
    brain.start(true, false);
    vi.advanceTimersByTime(TIMING.firstAppearance);
    const last = (): string => (commands.filter((c) => c.type === 'play').at(-1) as Extract<BrainCommand, { type: 'play' }>).action;
    brain.clipDone(last() as never);
    vi.advanceTimersByTime(TIMING.stay);
    expect(brain.state).toBe('exiting');
    expect(EXITS as readonly string[]).toContain(last());
    brain.chatOpened();
    expect(brain.state).toBe('docked');
    expect(last()).toBe('seat-route');
  });

  it('reduced motion: no exits at all (static pose as before); chat open: he never exits', () => {
    const commands: BrainCommand[] = [];
    const brain = new MascotBrain((c) => commands.push(c), { rng: seeded(5), width: () => 1200 });
    brain.start(true, true);
    vi.advanceTimersByTime(TIMING.firstAppearance + TIMING.maxGap * 2);
    expect(commands.some((c) => c.type === 'play' && (EXITS as readonly string[]).includes(c.action))).toBe(false);

    const open: BrainCommand[] = [];
    const b2 = new MascotBrain((c) => open.push(c), { rng: seeded(5), width: () => 1200 });
    b2.start(true, false);
    b2.chatOpened();
    vi.advanceTimersByTime(TIMING.maxGap * 3);
    expect(open.some((c) => c.type === 'play' && (EXITS as readonly string[]).includes(c.action))).toBe(false);
  });
});

describe('engine and renderer: the mask reaches the SVG', () => {
  it('the body and its shadow are clipped while he goes through the door, and unclipped afterwards', () => {
    const e = new MascotEngine(STAGE, seeded(2));
    e.play('static', { targetX: 500, blend: 0 });
    e.tick(0.2);
    e.play('exit-door');
    const host = document.createElement('div');
    const r = new MascotRenderer(host, document);
    let clipped = false;
    let hitGone = false;
    for (let i = 0; i < 900; i++) {
      const { frame } = e.tick(1 / 60);
      r.render(frame, { width: 1200, height: 800 }, true);
      const body = host.querySelector('g.body')!;
      if (frame.mask) {
        clipped ||= (body.getAttribute('clip-path') ?? '').startsWith('url(#mascot-clip-');
        hitGone ||= !host.querySelector('path.hit')!.getAttribute('d');
      }
    }
    expect(clipped).toBe(true);
    expect(hitGone).toBe(true);
    expect(host.querySelector('clipPath path')!.getAttribute('d')).toBeTruthy();
    e.play('idle-breathe');
    const { frame } = e.tick(1 / 60);
    r.render(frame, { width: 1200, height: 800 }, true);
    expect(host.querySelector('g.body')!.getAttribute('clip-path')).toBeNull();
    r.destroy();
  });
});
