import { ROUTE_ACTIONS, RouteAction, Stage, sitting, standAt } from './animations';
import { BrainCommand, MascotBrain, TIMING, dispatchEngineEvent } from './brain';
import { MascotEngine } from './mascot-engine';
import { MAX_CLIMB_SPEED, allowedRoutes, chooseRoute, createRouteClip, routeGeometry, stairSteps } from './seat-routes';
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
    const near = allowedRoutes({ dx: 120, height: 150, room: 400, panelTop: 600, seatInset: 250 }, false);
    expect(near['route-hop']).toBeGreaterThan(0);
    expect(near['route-stairs']).toBeGreaterThan(0);
    expect(near['route-rope']).toBeUndefined();
    expect(near['route-vault']).toBeUndefined();
  });

  it('far and high: rope, ladder and balloon allowed; no short hop', () => {
    const far = allowedRoutes({ dx: 350, height: 500, room: 700, panelTop: 240, seatInset: 60 }, false);
    expect(far['route-rope']).toBeGreaterThan(0);
    expect(far['route-ladder']).toBeGreaterThan(0);
    expect(far['route-balloon']).toBeGreaterThan(0);
    expect(far['route-hop']).toBeUndefined();
  });

  it('panel at the very top: no rope from above and no balloon', () => {
    const top = allowedRoutes({ dx: 400, height: 700, room: 500, panelTop: 40, seatInset: 250 }, false);
    expect(top['route-rope']).toBeUndefined();
    expect(top['route-balloon']).toBeUndefined();
  });

  it('lite mode keeps only the simple routes', () => {
    const lite = allowedRoutes({ dx: 700, height: 300, room: 700, panelTop: 400, seatInset: 250 }, true);
    for (const r of Object.keys(lite)) {
      expect(['route-hop', 'route-stairs', 'route-climb']).toContain(r);
    }
  });

  it('never picks the same route twice in a row', () => {
    const g = { dx: 500, height: 300, room: 600, panelTop: 400, seatInset: 250 };
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
        expect(clip.duration).toBeLessThanOrEqual(6.1);
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
    e.play('peek-out');
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
    e.play('peek-out');
    expect(e.airborne).toBe(false);
    expect(e.currentAction).toBe('peek-out');
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

// Exhaustive (every route × layouts × starts): slower on CI runners than the 5 s default.
describe('edge cases: every allowed route stays inside the viewport', { timeout: 60_000 }, () => {
  const env = { vx: 0, vy: 0, spin: 0, pointer: null };
  const ALL_JOINTS = ['head', 'neck', 'hip', 'shoulder', 'lElbow', 'rElbow', 'lHand', 'rHand', 'lKnee', 'rKnee', 'lFoot', 'rFoot'] as const;

  /** Absolute points of an ink path (M/L/Q); relative arcs (the balloon) are skipped. */
  function inkPoints(d: string): { x: number; y: number }[] {
    const out: { x: number; y: number }[] = [];
    const tokens = d.match(/[A-Za-z]|-?\d+(?:\.\d+)?/g) ?? [];
    let cmd = '';
    let nums: number[] = [];
    const flush = (): void => {
      if (cmd === 'M' || cmd === 'L' || cmd === 'Q') {
        for (let i = 0; i + 1 < nums.length; i += 2) {
          out.push({ x: nums[i], y: nums[i + 1] });
        }
      }
      nums = [];
    };
    for (const t of tokens) {
      if (/[A-Za-z]/.test(t)) {
        flush();
        cmd = t;
      } else {
        nums.push(Number(t));
      }
    }
    flush();
    return out;
  }

  const sheet = (w: number, h: number, top: number): Stage => ({
    width: w,
    height: h,
    ground: h - 2,
    seat: { x: w * 0.64, y: top },
    panel: { left: 0, top, right: w, bottom: h },
    corner: { x: w - 34, y: h - 34 },
  });
  const desk = (panel: { left: number; top: number; right: number; bottom: number }): Stage => ({
    width: 1200,
    height: 800,
    ground: 798,
    seat: { x: panel.left + (panel.right - panel.left) * 0.64, y: panel.top },
    panel,
    corner: { x: 1166, y: 766 },
  });
  const CONFIGS: [string, Stage][] = [
    ['landscape bottom sheet 667x375', sheet(667, 375, 120)],
    ['portrait bottom sheet 375x812', sheet(375, 812, 228)],
    ['desktop, panel on the right', STAGE],
    ['desktop, little room on the right', desk({ left: 400, top: 300, right: 1180, bottom: 784 })],
    ['desktop, very tall panel (top near 0)', desk({ left: 794, top: 10, right: 1184, bottom: 784 })],
    ['desktop, low panel', desk({ left: 794, top: 548, right: 1184, bottom: 784 })],
  ];

  it.each(CONFIGS)('%s', (_name, stage) => {
    const seated = sitting(stage.seat!.x, stage.seat!.y, -1, 0);
    const sj = forwardKinematics(seated);
    // The seated pose itself may reach above a panel that touches the top edge; allow that region (+3 px).
    const topLimit = Math.min(0, ...ALL_JOINTS.map((k) => seated.y + sj[k].y) .map((y) => y - 12));
    let runs = 0;
    for (const lite of [false, true]) {
      for (const startX of [30, stage.width * 0.3, stage.width * 0.6, stage.width - 30]) {
        const from = standAt(stage, startX, 1);
        const allowed = Object.keys(allowedRoutes(routeGeometry(stage, from), lite)) as RouteAction[];
        expect(allowed.length).toBeGreaterThan(0);
        for (const route of allowed) {
          runs++;
          const clip = createRouteClip(route, { stage, from, side: 'left', targetX: startX, rng: seeded(3) }, lite);
          let prev: ReturnType<typeof world> | null = null;
          for (let t = 0; t <= clip.duration + 1e-9; t += 1 / 60) {
            const f = clip.sample(t, env);
            const j = forwardKinematics(f.pose);
            for (const k of ALL_JOINTS) {
              const x = f.pose.x + j[k].x;
              const y = f.pose.y + j[k].y;
              const where = `${route} ${k} at t=${t.toFixed(2)} from ${Math.round(startX)}`;
              expect(x, where).toBeGreaterThanOrEqual(-1);
              expect(x, where).toBeLessThanOrEqual(stage.width + 1);
              expect(y, where).toBeGreaterThanOrEqual(topLimit);
              expect(y, where).toBeLessThanOrEqual(stage.height + 1);
            }
            for (const it of f.props?.ink ?? []) {
              for (const q of inkPoints(it.d)) {
                expect(q.x, `${route} prop x`).toBeGreaterThanOrEqual(-1);
                expect(q.x, `${route} prop x`).toBeLessThanOrEqual(stage.width + 1);
                expect(q.y, `${route} prop y`).toBeGreaterThanOrEqual(-1);
                expect(q.y, `${route} prop y`).toBeLessThanOrEqual(stage.height + 1);
              }
            }
            const cur = world(f.pose, j);
            if (prev) {
              expect(maxJump(cur, prev), `${route} jump at t=${t.toFixed(2)}`).toBeLessThan(40);
            }
            prev = cur;
          }
        }
      }
    }
    expect(runs).toBeGreaterThan(0);
  });

  it('bottom sheet (no room beside the panel): no stairs or other side routes, a hop straight up', () => {
    const land = sheet(667, 375, 120);
    const g = routeGeometry(land, standAt(land, 100, 1));
    expect(g.room).toBe(0);
    expect(Object.keys(allowedRoutes(g, false))).toEqual(['route-hop']);
    const portrait = sheet(375, 812, 228);
    expect(Object.keys(allowedRoutes(routeGeometry(portrait, standAt(portrait, 100, 1)), false))).toEqual(['route-hop']);
  });

  it('stairs need room for every step (count from the same helper)', () => {
    const g = { dx: 400, height: 300, room: 0, panelTop: 400, seatInset: 250 };
    for (const room of [100, 200, 260, 400]) {
      const allowed = !!allowedRoutes({ ...g, room }, false)['route-stairs'];
      expect(allowed).toBe(room >= 22 * stairSteps(300) + 50);
    }
  });

  it('a tall climb is speed-capped and longer, not faster (MAX_CLIMB_SPEED, about 5 s)', () => {
    const tall = desk({ left: 794, top: 150, right: 1184, bottom: 784 });
    const from = standAt(tall, 760, 1);
    expect(allowedRoutes(routeGeometry(tall, from), false)['route-climb']).toBeGreaterThan(0);
    const clip = createRouteClip('route-climb', { stage: tall, from, side: 'left', targetX: 760, rng: seeded(1) }, false);
    expect(clip.duration).toBeLessThanOrEqual(5.8);
    let prevY: number | null = null;
    let maxSpeed = 0;
    for (let t = 0; t < clip.duration; t += 1 / 60) {
      const p = clip.sample(t, env).pose;
      const j = forwardKinematics(p);
      const midClimb = Math.abs(p.x + j.lHand.x - 794) < 3 && p.y < tall.ground - 90 && p.y > 210;
      if (midClimb && prevY !== null) {
        maxSpeed = Math.max(maxSpeed, Math.abs(p.y - prevY) * 60);
      }
      prevY = midClimb ? p.y : null;
    }
    expect(maxSpeed).toBeGreaterThan(50);
    expect(maxSpeed).toBeLessThanOrEqual(MAX_CLIMB_SPEED * 1.05);
    // Taller than that: the climb is not offered at all.
    const tallest = desk({ left: 794, top: 10, right: 1184, bottom: 784 });
    expect(allowedRoutes(routeGeometry(tallest, standAt(tallest, 300, 1)), false)['route-climb']).toBeUndefined();
  });
});

describe('interruptions mid-route', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('the live re-target state is eased in place and cleared when the route ends or the chat closes', () => {
    const e = new MascotEngine(STAGE, seeded(4));
    const live = e as unknown as { liveSeat: { x: number } | null; livePanel: object | null };
    e.play('static', { targetX: 300, blend: 0 });
    e.tick(0.3);
    e.play('seat-route');
    e.tick(1 / 60);
    const seatObj = live.liveSeat;
    expect(seatObj).not.toBeNull();
    e.tick(1 / 60);
    expect(live.liveSeat).toBe(seatObj);
    for (let i = 0; i < 500 && e.route; i++) {
      e.tick(1 / 60);
    }
    e.tick(1 / 60);
    expect(live.liveSeat).toBeNull();
    expect(live.livePanel).toBeNull();
    e.play('seat-route');
    e.tick(1 / 60);
    expect(live.liveSeat).not.toBeNull();
    e.setStage({ ...STAGE, seat: null, panel: null });
    e.tick(1 / 60);
    expect(live.liveSeat).toBeNull();
  });

  it('grabbing him mid-route: continuous hand-over to the hold, route over, props fade', () => {
    const e = new MascotEngine(STAGE, seeded(4));
    e.play('static', { targetX: 200, blend: 0 });
    e.tick(0.3);
    e.play('route-ladder');
    let f = e.tick(1 / 60).frame;
    for (let i = 0; i < 120; i++) {
      f = e.tick(1 / 60).frame;
    }
    const before = world(f.pose, f.joints);
    e.dragStart(f.pose.x, f.pose.y - 20);
    const after = e.tick(1 / 60).frame;
    expect(maxJump(world(after.pose, after.joints), before)).toBeLessThan(40);
    expect(e.held).toBe(true);
    expect(e.route).toBeNull();
    expect(Math.max(0, ...(after.props.ink ?? []).map((i) => i.alpha))).toBeLessThan(1);
  });

  it('chat closed and reopened mid-route: ends seated on the panel again, no jumps while drawn from clips', () => {
    const e = new MascotEngine(STAGE, seeded(9));
    const brain = new MascotBrain(
      (c) => {
        if (c.type === 'play') {
          e.play(c.action, { side: c.side, targetX: c.targetX, blend: c.blend, variant: c.variant });
        }
      },
      { rng: () => 0.5, width: () => 1200 },
    );
    let prev: ReturnType<typeof world> | null = null;
    let worst = 0;
    let worstAt = '';
    let fno = 0;
    const frames = (n: number): void => {
      for (let i = 0; i < n; i++) {
        vi.advanceTimersByTime(1000 / 60);
        const { frame, events } = e.tick(1 / 60);
        for (const ev of events) {
          dispatchEngineEvent(brain, ev);
        }
        const cur = world(frame.pose, frame.joints);
        if (prev && !e.ragdoll) {
          const d = maxJump(cur, prev);
          if (d > worst) {
            worst = d;
            worstAt = `${e.currentAction} f${fno}`;
          }
        }
        fno++;
        prev = e.ragdoll ? null : cur;
      }
    };
    brain.start(true, false);
    vi.advanceTimersByTime(TIMING.firstAppearance);
    // Let whichever entrance was picked finish for real, then measure from the route on.
    for (let i = 0; i < 900 && brain.state !== 'idle'; i++) {
      frames(1);
    }
    expect(brain.state).toBe('idle');
    worst = 0;
    brain.chatOpened();
    frames(70);
    expect(e.route).not.toBeNull();
    brain.chatClosed();
    frames(12);
    brain.chatOpened();
    for (let i = 0; i < 1500 && !(brain.state === 'docked' && e.currentAction === 'docked'); i++) {
      frames(1);
    }
    expect(brain.state).toBe('docked');
    expect(e.currentAction).toBe('docked');
    frames(60);
    const seat = sitting(STAGE.seat!.x, STAGE.seat!.y, -1, 0);
    expect(Math.hypot(e.root.x - seat.x, e.root.y - seat.y)).toBeLessThan(6);
    expect(worst, worstAt).toBeLessThan(40);
  });
});
