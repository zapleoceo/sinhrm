import { ActivityAction, EXITS, MoveAction, Stage, sitting, standAt } from './animations';
import { ACTIVITIES, ACTIVITY_ACTIONS, activityEnd, createActivityClip } from './activities';
import { BrainCommand, EXIT_INSET, MascotBrain, RECENT_MEMORY, TIMING, windyToday } from './brain';
import { MascotEngine } from './mascot-engine';
import { MOVES, MOVE_ACTIONS, createMoveClip, moveEnd } from './moves';
import { Joints, Pose, forwardKinematics } from './skeleton';

const STAGE: Stage = { width: 1200, height: 800, ground: 798, seat: { x: 1000, y: 300 }, corner: { x: 1166, y: 766 } };
const KEYS = ['head', 'hip', 'lHand', 'rHand', 'lFoot', 'rFoot', 'lKnee', 'rKnee', 'lElbow', 'rElbow'] as const;
const env = { vx: 0, vy: 0, spin: 0, pointer: null };

function seeded(seed = 3): () => number {
  let s = seed;
  return () => {
    s = (s * 16807) % 2147483647;
    return (s - 1) / 2147483646;
  };
}

type W = Record<(typeof KEYS)[number], { x: number; y: number }>;
function world(p: Pose, j: Joints = forwardKinematics(p)): W {
  const out = {} as W;
  for (const k of KEYS) {
    out[k] = { x: p.x + j[k].x, y: p.y + j[k].y };
  }
  return out;
}
const jump = (a: W, b: W): number => Math.max(...KEYS.map((k) => Math.hypot(a[k].x - b[k].x, a[k].y - b[k].y)));

/** Continuity + known start/end for one clip. */
function checkClip(name: string, clip: { duration: number; sample(t: number, e: typeof env): { pose: Pose; props?: { ink?: { d: string }[] | null } } }, from: Pose | null, end: Pose): void {
  const first = world(clip.sample(0, env).pose);
  if (from) {
    expect(jump(first, world(from)), `${name} start`).toBeLessThan(2);
  }
  let prev = first;
  for (let t = 1 / 60; t <= clip.duration + 1e-9; t += 1 / 60) {
    const f = clip.sample(t, env);
    const cur = world(f.pose);
    for (const k of KEYS) {
      expect(Number.isFinite(cur[k].x) && Number.isFinite(cur[k].y), `${name} finite`).toBe(true);
    }
    expect(jump(cur, prev), `${name} jump at t=${t.toFixed(2)}`).toBeLessThan(40);
    for (const it of f.props?.ink ?? []) {
      expect(it.d.includes('NaN'), `${name} prop`).toBe(false);
    }
    prev = cur;
  }
  expect(jump(world(clip.sample(clip.duration, env).pose), world(end)), `${name} end`).toBeLessThan(2);
}

describe('locomotion moves: continuous, start at the current pose, end standing at the target', () => {
  it.each(MOVE_ACTIONS.map((m) => [m]))('%s', (move) => {
    for (const [x0, x1] of [
      [300, 800],
      [900, 350],
    ]) {
      for (const lite of [false, true]) {
        const from = standAt(STAGE, x0, x1 > x0 ? 1 : -1);
        const c = { stage: STAGE, from, x0, x1, lite, rng: seeded(2), cut: false };
        const clip = createMoveClip(move, c);
        expect(clip.duration).toBeGreaterThan(0.8);
        checkClip(`${move} ${x0}→${x1}`, clip, from, moveEnd(move, c));
      }
    }
  });

  it.each(MOVE_ACTIONS.filter((m) => MOVES[m].entrance).map((m) => [m]))('%s as an entrance from off screen', (move: MoveAction) => {
    const from = standAt(STAGE, -200, 1);
    const c = { stage: STAGE, from, x0: -60, x1: 500, lite: false, rng: seeded(2), cut: true };
    const clip = createMoveClip(move, c);
    checkClip(`${move} entrance`, clip, null, moveEnd(move, c));
  });
});

describe('idle activities: continuous, 3–8 s, end where they started', () => {
  it.each(ACTIVITY_ACTIONS.map((a) => [a]))('%s', (act: ActivityAction) => {
    const docked = ACTIVITIES[act].docked;
    for (const lite of [false, true]) {
      const from = docked ? sitting(STAGE.seat!.x, STAGE.seat!.y, -1, 0) : standAt(STAGE, 500, lite ? -1 : 1);
      const c = { stage: STAGE, from, lite, rng: seeded(4), seat: STAGE.seat, pointer: { x: 100, y: 300 } };
      const clip = createActivityClip(act, c);
      expect(clip.duration).toBeGreaterThanOrEqual(3);
      expect(clip.duration).toBeLessThanOrEqual(8.2);
      checkClip(act, clip, from, activityEnd(act, c));
    }
  });

  it('props stay drawable inside the viewport (plane, kite)', () => {
    for (const act of ['act-plane', 'act-kite'] as ActivityAction[]) {
      for (const x of [60, 600, 1140]) {
        const from = standAt(STAGE, x, x < 600 ? 1 : -1);
        const clip = createActivityClip(act, { stage: STAGE, from, lite: false, rng: seeded(1), seat: null, pointer: null });
        for (let t = 0; t < clip.duration; t += 0.1) {
          for (const it of clip.sample(t, env).props?.ink ?? []) {
            for (const m of it.d.matchAll(/[ML] (-?[\d.]+) (-?[\d.]+)/g)) {
              expect(Number(m[1])).toBeGreaterThanOrEqual(-15);
              expect(Number(m[1])).toBeLessThanOrEqual(STAGE.width + 15);
              expect(Number(m[2])).toBeGreaterThanOrEqual(0);
            }
          }
        }
      }
    }
  });
});

describe('variety system', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  function idleBrain(opts: { lite?: boolean; hour?: number; windy?: boolean; rng?: () => number } = {}) {
    const commands: BrainCommand[] = [];
    const brain = new MascotBrain((c) => commands.push(c), { rng: opts.rng ?? seeded(11), width: () => 1200, hour: () => opts.hour ?? 14, windy: () => opts.windy ?? false });
    brain.start(true, false);
    if (opts.lite) {
      brain.setLite(true);
    }
    vi.advanceTimersByTime(TIMING.firstAppearance);
    const plays = (): Extract<BrainCommand, { type: 'play' }>[] => commands.filter((c): c is Extract<BrainCommand, { type: 'play' }> => c.type === 'play');
    brain.clipDone(plays().at(-1)!.action);
    return { brain, plays };
  }

  /** Runs `n` idle cycles and returns the picks (fidgets, activities, wander moves). */
  function picks(b: ReturnType<typeof idleBrain>, n: number): string[] {
    const out: string[] = [];
    for (let i = 0; i < n * 2; i++) {
      const last = b.plays().at(-1)!.action;
      if (last !== 'idle-breathe') {
        out.push(last);
      }
      b.brain.clipDone(last);
    }
    return out;
  }

  it('nothing repeats within the last 6 picks', () => {
    const b = idleBrain();
    const seq = picks(b, 60);
    expect(seq.length).toBeGreaterThan(50);
    for (let i = 0; i < seq.length; i++) {
      expect(seq.slice(Math.max(0, i - RECENT_MEMORY + 1), i)).not.toContain(seq[i]);
    }
    // Plenty of variety: fidgets, activities and moves all show up.
    expect(seq.some((a) => a.startsWith('act-'))).toBe(true);
    expect(seq.some((a) => a.startsWith('move-'))).toBe(true);
    expect(seq.some((a) => a.startsWith('idle-'))).toBe(true);
  });

  it('wandering sends him to a new spot with a move', () => {
    const b = idleBrain();
    const wanders = b.plays();
    picks(b, 40);
    const moves = wanders.filter((c) => c.action.startsWith('move-'));
    expect(moves.length).toBeGreaterThan(0);
    for (const m of moves) {
      expect(m.targetX).toBeGreaterThanOrEqual(60);
      expect(m.targetX).toBeLessThanOrEqual(1140);
      expect(MOVES[m.action as MoveAction].wander).toBe(true);
    }
  });

  it('lite mode keeps only the cheaper moves and activities (no bubbles, no kite, no vehicles)', () => {
    const b = idleBrain({ lite: true, windy: true });
    const choices = b.brain.idleChoices();
    expect(choices['act-bubbles']).toBeUndefined();
    expect(choices['act-kite']).toBeUndefined();
    const seq = picks(b, 60);
    for (const a of seq) {
      if (a.startsWith('move-')) {
        expect(MOVES[a as MoveAction].lite, a).toBe(true);
      }
      if (a.startsWith('act-')) {
        expect(ACTIVITIES[a as ActivityAction].lite, a).toBe(true);
      }
    }
  });

  it('kite only on windy days; fishing never on the floor', () => {
    expect(idleBrain({ windy: false }).brain.idleChoices()['act-kite']).toBeUndefined();
    expect(idleBrain({ windy: true }).brain.idleChoices()['act-kite']).toBeGreaterThan(0);
    expect(idleBrain().brain.idleChoices()['act-fishing']).toBeUndefined();
    expect(typeof windyToday(new Date(2026, 8, 29))).toBe('boolean');
  });

  it('time of day: coffee in the morning, yawning and stretching late in the evening', () => {
    const morning = idleBrain({ hour: 8 }).brain.idleChoices();
    const noon = idleBrain({ hour: 14 }).brain.idleChoices();
    const night = idleBrain({ hour: 23 }).brain.idleChoices();
    expect(morning['act-coffee']).toBeGreaterThan(noon['act-coffee'] * 2);
    expect(night['idle-yawn']).toBeGreaterThan(noon['idle-yawn'] * 2);
    expect(night['idle-stretch']).toBeGreaterThan(noon['idle-stretch'] * 2);
  });

  it('reduced motion: none of the new moves or activities', () => {
    const commands: BrainCommand[] = [];
    const brain = new MascotBrain((c) => commands.push(c), { rng: seeded(3), width: () => 1200 });
    brain.start(true, true);
    vi.advanceTimersByTime(TIMING.maxGap * 3);
    const plays = commands.filter((c) => c.type === 'play').map((c) => (c as { action: string }).action);
    expect(plays.filter((a) => a.startsWith('move-') || a.startsWith('act-'))).toEqual([]);
  });

  it('entrances sometimes use a move from off screen; exits use a move to the side, then leave in the scene', () => {
    const entrances: Extract<BrainCommand, { type: 'play' }>[] = [];
    const exits: Extract<BrainCommand, { type: 'play' }>[] = [];
    for (let seed = 1; seed < 40; seed++) {
      const commands: BrainCommand[] = [];
      const brain = new MascotBrain((c) => commands.push(c), { rng: seeded(seed), width: () => 1200 });
      brain.start(true, false);
      vi.advanceTimersByTime(TIMING.firstAppearance);
      const first = commands.filter((c): c is Extract<BrainCommand, { type: 'play' }> => c.type === 'play').at(-1)!;
      entrances.push(first);
      // Finish the entrance (a peek may continue with peek-out or duck away).
      for (let i = 0; i < 3 && brain.state === 'entering'; i++) {
        brain.clipDone(commands.filter((c): c is Extract<BrainCommand, { type: 'play' }> => c.type === 'play').at(-1)!.action);
      }
      if (brain.state !== 'idle') {
        continue;
      }
      vi.advanceTimersByTime(TIMING.stay);
      const exit = commands.filter((c): c is Extract<BrainCommand, { type: 'play' }> => c.type === 'play').at(-1)!;
      exits.push(exit);
      brain.clipDone(exit.action);
      if (exit.action.startsWith('move-')) {
        const scene = commands.filter((c): c is Extract<BrainCommand, { type: 'play' }> => c.type === 'play').at(-1)!;
        expect(EXITS as readonly string[]).toContain(scene.action);
        brain.clipDone(scene.action);
      }
      expect(brain.state).toBe('offstage');
    }
    const moveIn = entrances.filter((c) => c.action.startsWith('move-'));
    const moveOut = exits.filter((c) => c.action.startsWith('move-'));
    expect(moveIn.length).toBeGreaterThan(3);
    expect(moveOut.length).toBeGreaterThan(3);
    for (const c of moveIn) {
      expect(c.fromX === -60 || c.fromX === 1260).toBe(true);
      expect(MOVES[c.action as MoveAction].entrance).toBe(true);
    }
    for (const c of moveOut) {
      // Stops on screen, near a side (never past the edge).
      expect(c.targetX === EXIT_INSET || c.targetX === 1200 - EXIT_INSET).toBe(true);
    }
  });

  it('docked: now and then goes fishing off the panel edge, then sits again', () => {
    const commands: BrainCommand[] = [];
    const brain = new MascotBrain((c) => commands.push(c), { rng: () => 0.5, width: () => 1200 });
    brain.start(true, false);
    brain.chatOpened();
    vi.advanceTimersByTime(45_000);
    const plays = commands.filter((c) => c.type === 'play').map((c) => (c as { action: string }).action);
    expect(plays).toContain('act-fishing');
    brain.clipDone('act-fishing');
    expect(commands.filter((c) => c.type === 'play').at(-1)).toMatchObject({ action: 'docked' });
  });
});

describe('moves in the engine', () => {
  it('a move played by the engine ends standing at the target and the clip reports done', () => {
    const e = new MascotEngine(STAGE, seeded(2));
    e.play('static', { targetX: 300, blend: 0 });
    e.tick(0.3);
    e.play('move-skateboard', { targetX: 800 });
    let done = false;
    for (let i = 0; i < 600 && !done; i++) {
      done = e.tick(1 / 60).events.some((ev) => ev.type === 'clipDone');
    }
    expect(done).toBe(true);
    expect(Math.abs(e.root.x - 800)).toBeLessThan(2);
  });

  it('the heaviest move and activity stay cheap per frame', () => {
    const cost = (action: 'move-unicycle' | 'act-bubbles' | 'act-kite' | 'move-skateboard'): number => {
      const e = new MascotEngine(STAGE, seeded(2));
      e.play('static', { targetX: 300, blend: 0 });
      e.tick(0.3);
      e.play(action, { targetX: 800 });
      const n = 300;
      const t0 = performance.now();
      for (let i = 0; i < n; i++) {
        e.tick(1 / 60);
      }
      return (performance.now() - t0) / n;
    };
    for (const a of ['move-unicycle', 'act-bubbles', 'act-kite', 'move-skateboard'] as const) {
      expect(cost(a), a).toBeLessThan(0.5);
    }
  });
});
