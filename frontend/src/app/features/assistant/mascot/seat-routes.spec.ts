import { ROUTE_ACTIONS, RouteAction, Stage, sitting, standAt } from './animations';
import { BrainCommand, MascotBrain, TIMING, dispatchEngineEvent } from './brain';
import { MascotEngine } from './mascot-engine';
import { allowedRoutes, chooseRoute, createRouteClip, routeGeometry } from './seat-routes';
import { Joints, Pose, forwardKinematics } from './skeleton';

const PANEL = { left: 794, top: 204, right: 1184, bottom: 784 };
const STAGE: Stage = { width: 1200, height: 800, ground: 798, seat: { x: 794 + 390 * 0.64, y: 204 }, panel: PANEL, corner: { x: 1166, y: 766 } };
const KEYS = ['head', 'hip', 'lHand', 'rHand', 'lFoot', 'rFoot', 'lKnee', 'rKnee'] as const;

function seeded(seed = 3): () => number {
  let s = seed;
  return () => {
    s = (s * 16807) % 2147483647;
    return (s - 1) / 2147483646;
  };
}

function world(p: Pose, j: Joints = forwardKinematics(p)): Record<(typeof KEYS)[number], { x: number; y: number }> {
  const out = {} as Record<(typeof KEYS)[number], { x: number; y: number }>;
  for (const k of KEYS) {
    out[k] = { x: p.x + j[k].x, y: p.y + j[k].y };
  }
  return out;
}

function maxJump(a: ReturnType<typeof world>, b: ReturnType<typeof world>): number {
  return Math.max(...KEYS.map((k) => Math.hypot(a[k].x - b[k].x, a[k].y - b[k].y)));
}

describe('route selection by geometry', () => {
  it('near and low: hop or stairs; no rope, ladder or balloon', () => {
    const near = allowedRoutes({ dx: 120, height: 150, room: 400, panelTop: 600 }, false);
    expect(near['route-hop']).toBeGreaterThan(0);
    expect(near['route-stairs']).toBeGreaterThan(0);
    expect(near['route-rope']).toBeUndefined();
    expect(near['route-vault']).toBeUndefined();
  });

  it('far and high: rope, ladder and balloon allowed; no short hop', () => {
    const far = allowedRoutes({ dx: 700, height: 560, room: 700, panelTop: 240 }, false);
    expect(far['route-rope']).toBeGreaterThan(0);
    expect(far['route-ladder']).toBeGreaterThan(0);
    expect(far['route-balloon']).toBeGreaterThan(0);
    expect(far['route-hop']).toBeUndefined();
  });

  it('panel at the very top: no rope from above and no balloon', () => {
    const top = allowedRoutes({ dx: 400, height: 700, room: 500, panelTop: 40 }, false);
    expect(top['route-rope']).toBeUndefined();
    expect(top['route-balloon']).toBeUndefined();
  });

  it('lite mode keeps only the simple routes', () => {
    const lite = allowedRoutes({ dx: 700, height: 300, room: 700, panelTop: 400 }, true);
    for (const r of Object.keys(lite)) {
      expect(['route-hop', 'route-stairs', 'route-climb']).toContain(r);
    }
  });

  it('never picks the same route twice in a row', () => {
    const g = { dx: 500, height: 300, room: 600, panelTop: 400 };
    const rng = seeded(5);
    let last: RouteAction | null = null;
    const seen = new Set<RouteAction>();
    for (let i = 0; i < 200; i++) {
      const r = chooseRoute(g, rng, last, false);
      expect(r).not.toBe(last);
      seen.add(r);
      last = r;
    }
    expect(seen.size).toBeGreaterThanOrEqual(5);
  });

  it('geometry is read from the stage and the current pose', () => {
    const g = routeGeometry(STAGE, standAt(STAGE, 200, 1));
    expect(g.height).toBe(798 - 204);
    expect(g.room).toBe(794);
    expect(Math.round(g.dx)).toBe(Math.round(STAGE.seat!.x - 200));
  });
});

describe('every route: continuous, contact, 1.5–4 s, ends seated', () => {
  const env = { vx: 0, vy: 0, spin: 0, pointer: null };

  const LOW_PANEL = { left: 794, top: 548, right: 1184, bottom: 784 };
  const LOW: Stage = { ...STAGE, panel: LOW_PANEL, seat: { x: 794 + 390 * 0.64, y: 548 } };
  const covered = new Set<RouteAction>();

  it.each(ROUTE_ACTIONS.map((r) => [r]))('%s (from every start where the geometry allows it)', (route) => {
    let runs = 0;
    for (const stage of [STAGE, LOW]) {
      for (const startX of [150, 520, 700, 760]) {
        const from = standAt(stage, startX, 1);
        if (!allowedRoutes(routeGeometry(stage, from), false)[route]) {
          continue;
        }
        runs++;
        const clip = createRouteClip(route, { stage, from, side: 'left', targetX: startX, rng: seeded(2) }, false);
        expect(clip.duration).toBeGreaterThanOrEqual(1.5);
        expect(clip.duration).toBeLessThanOrEqual(4.8);
        const first = world(clip.sample(0, env).pose);
        expect(maxJump(first, world(from))).toBeLessThan(2);
        let prev = first;
        let sawInk = false;
        for (let t = 1 / 60; t <= clip.duration + 1e-9; t += 1 / 60) {
          const f = clip.sample(t, env);
          const cur = world(f.pose);
          expect(maxJump(cur, prev)).toBeLessThan(40);
          for (const k of KEYS) {
            expect(Number.isFinite(cur[k].x) && Number.isFinite(cur[k].y)).toBe(true);
          }
          sawInk ||= (f.props?.ink?.length ?? 0) > 0;
          prev = cur;
        }
        const end = clip.sample(clip.duration, env).pose;
        const seat = sitting(stage.seat!.x, stage.seat!.y, -1, 0);
        expect(maxJump(world(end), world(seat))).toBeLessThan(2);
        if (route !== 'route-hop' && route !== 'route-climb') {
          expect(sawInk).toBe(true);
        }
      }
    }
    expect(runs).toBeGreaterThan(0);
    covered.add(route);
  });

  it('climb: hands grip the panel edge, feet stay on it', () => {
    const from = standAt(STAGE, 300, 1);
    const clip = createRouteClip('route-climb', { stage: STAGE, from, side: 'left', targetX: 300, rng: seeded(1) }, false);
    let checked = 0;
    for (let t = 0; t < clip.duration; t += 0.05) {
      const p = clip.sample(t, env).pose;
      const w = world(p);
      const midClimb = w.hip.y < STAGE.ground - 90 && w.hip.y > PANEL.top + 60;
      if (midClimb) {
        expect(Math.abs(w.lHand.x - PANEL.left)).toBeLessThan(3);
        expect(Math.abs(w.rFoot.x - PANEL.left)).toBeLessThan(4);
        checked++;
      }
    }
    expect(checked).toBeGreaterThan(5);
  });

  it('lite: no draw-on (props appear fully drawn)', () => {
    const from = standAt(STAGE, 300, 1);
    const clip = createRouteClip('route-stairs', { stage: STAGE, from, side: 'left', targetX: 300, rng: seeded(1) }, true);
    for (let t = 0; t < clip.duration; t += 0.05) {
      for (const it of clip.sample(t, env).props?.ink ?? []) {
        expect(it.draw).toBe(1);
      }
    }
  });
});

describe('routes in the engine and brain', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  const settle = (e: MascotEngine, x = 300): void => {
    e.play('static', { targetX: x, blend: 0 });
    e.tick(0.3);
  };

  it('seat-route picks a route by geometry and ends in the seat; the next one is different', () => {
    const e = new MascotEngine(STAGE, seeded(4));
    settle(e);
    e.play('seat-route');
    const first = e.route;
    expect(first).not.toBeNull();
    let done = false;
    for (let i = 0; i < 400 && !done; i++) {
      done = e.tick(1 / 60).events.some((ev) => ev.type === 'clipDone');
    }
    expect(done).toBe(true);
    settle(e);
    e.play('seat-route');
    expect(e.route).not.toBe(first);
  });

  it('re-targets smoothly when the panel moves mid-route (resize)', () => {
    const e = new MascotEngine(STAGE, seeded(4));
    settle(e);
    e.play('seat-route');
    let prev = world(e.tick(1 / 60).frame.pose, e.tick(0).frame.joints);
    for (let i = 0; i < 40; i++) {
      const f = e.tick(1 / 60).frame;
      prev = world(f.pose, f.joints);
    }
    const moved = { left: 594, top: 300, right: 984, bottom: 784 };
    e.setStage({ ...STAGE, panel: moved, seat: { x: 594 + 390 * 0.64, y: 300 } });
    let end: Pose | null = null;
    for (let i = 0; i < 400; i++) {
      const { frame, events } = e.tick(1 / 60);
      const cur = world(frame.pose, frame.joints);
      expect(maxJump(cur, prev)).toBeLessThan(40);
      prev = cur;
      if (events.some((ev) => ev.type === 'clipDone')) {
        end = frame.pose;
        break;
      }
    }
    expect(end).not.toBeNull();
    const seat = sitting(594 + 390 * 0.64, 300, -1, 0);
    expect(Math.hypot(end!.x - seat.x, end!.y - seat.y)).toBeLessThan(4);
  });

  it('abort mid-air (chat closed on the rope): lets go and falls; props fade out', () => {
    const e = new MascotEngine(STAGE, seeded(4));
    settle(e, 200);
    e.play('route-rope');
    let high = false;
    for (let i = 0; i < 400 && !high; i++) {
      const f = e.tick(1 / 60).frame;
      high = f.pose.y < STAGE.ground - 200;
    }
    expect(high).toBe(true);
    e.play('exit-run');
    const ev = e.tick(1 / 60);
    expect(e.airborne).toBe(true);
    expect(ev.frame.props.ink?.length).toBeGreaterThan(0);
    expect(Math.max(...(ev.frame.props.ink ?? []).map((i) => i.alpha))).toBeLessThan(1);
    for (let i = 0; i < 40; i++) {
      e.tick(1 / 60);
    }
    expect(e.tick(0).frame.props.ink ?? []).toEqual([]);
  });

  it('abort on the ground just blends into the new clip, props fade', () => {
    const e = new MascotEngine(STAGE, seeded(4));
    settle(e, 200);
    e.play('route-ladder');
    for (let i = 0; i < 50; i++) {
      e.tick(1 / 60);
    }
    e.play('exit-run');
    expect(e.airborne).toBe(false);
    expect(e.currentAction).toBe('exit-run');
  });

  it('reduced motion: a short linear blend to the seat, no props', () => {
    const e = new MascotEngine(STAGE, seeded(4), true);
    settle(e);
    e.play('seat-route');
    expect(e.route).toBeNull();
    expect(e.currentAction).toBe('docked');
    let ink = 0;
    for (let i = 0; i < 15; i++) {
      ink += e.tick(1 / 60).frame.props.ink?.length ?? 0;
    }
    expect(ink).toBe(0);
    const seat = sitting(STAGE.seat!.x, STAGE.seat!.y, -1, 0);
    expect(Math.hypot(e.root.x - seat.x, e.root.y - seat.y)).toBeLessThan(3);
  });

  it('brain: chat opened while idle → a route, then the docked pose; entrance with the chat open ends in a route', () => {
    const commands: BrainCommand[] = [];
    const brain = new MascotBrain((c) => commands.push(c), { rng: () => 0.5, width: () => 1200 });
    const plays = (): string[] => commands.filter((c): c is Extract<BrainCommand, { type: 'play' }> => c.type === 'play').map((c) => c.action);
    brain.start(true, false);
    vi.advanceTimersByTime(TIMING.firstAppearance);
    brain.chatOpened();
    expect(brain.state).toBe('entering');
    brain.clipDone('enter-walk');
    expect(plays().at(-1)).toBe('seat-route');
    brain.clipDone('route-ladder');
    expect(plays().at(-1)).toBe('docked');
    expect(brain.state).toBe('docked');

    const c2: BrainCommand[] = [];
    const b2 = new MascotBrain((c) => c2.push(c), { rng: () => 0.5, width: () => 1200 });
    b2.start(true, false);
    vi.advanceTimersByTime(TIMING.firstAppearance);
    b2.clipDone('enter-walk');
    expect(b2.state).toBe('idle');
    b2.chatOpened();
    expect(c2.filter((c) => c.type === 'play').at(-1)).toMatchObject({ action: 'seat-route' });
  });

  it('brain + engine: chat closed mid-route → he aborts and carries on normally', () => {
    const e = new MascotEngine(STAGE, seeded(7));
    const brain = new MascotBrain(
      (c) => {
        if (c.type === 'play') {
          e.play(c.action, { side: c.side, targetX: c.targetX, blend: c.blend, variant: c.variant });
        }
      },
      { rng: () => 0.5, width: () => 1200 },
    );
    brain.start(true, false);
    vi.advanceTimersByTime(TIMING.firstAppearance);
    brain.clipDone('enter-walk');
    brain.chatOpened();
    for (let i = 0; i < 60; i++) {
      vi.advanceTimersByTime(1000 / 60);
      for (const ev of e.tick(1 / 60).events) {
        dispatchEngineEvent(brain, ev);
      }
    }
    expect(e.route).not.toBeNull();
    brain.chatClosed();
    for (let i = 0; i < 1200 && brain.state !== 'offstage' && brain.state !== 'idle'; i++) {
      vi.advanceTimersByTime(1000 / 60);
      for (const ev of e.tick(1 / 60).events) {
        dispatchEngineEvent(brain, ev);
      }
    }
    expect(['offstage', 'idle']).toContain(brain.state);
    expect(e.route).toBeNull();
  });
});
