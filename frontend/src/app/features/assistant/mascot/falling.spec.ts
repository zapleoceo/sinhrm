import { Stage } from './animations';
import { BrainCommand, MascotBrain, TIMING, dispatchEngineEvent } from './brain';
import { Joints } from './skeleton';
import { MascotEngine } from './mascot-engine';
import { P } from './ragdoll';

const STAGE: Stage = { width: 1200, height: 800, ground: 798, seat: null, corner: { x: 1166, y: 766 } };
const KEYS = ['head', 'neck', 'hip', 'lHand', 'rHand', 'lFoot', 'rFoot', 'lKnee', 'rKnee', 'lElbow', 'rElbow'] as const;
const RAG_INDEX: Record<(typeof KEYS)[number], number> = {
  head: P.Head,
  neck: P.Neck,
  hip: P.Pelvis,
  lHand: P.LHand,
  rHand: P.RHand,
  lFoot: P.LFoot,
  rFoot: P.RFoot,
  lKnee: P.LKnee,
  rKnee: P.RKnee,
  lElbow: P.LElbow,
  rElbow: P.RElbow,
};

function seeded(seed = 7): () => number {
  let s = seed;
  return () => {
    s = (s * 16807) % 2147483647;
    return (s - 1) / 2147483646;
  };
}

type World = Record<(typeof KEYS)[number], { x: number; y: number }>;

function worldJoints(root: { x: number; y: number }, j: Joints): World {
  const out = {} as World;
  for (const k of KEYS) {
    out[k] = { x: root.x + j[k].x, y: root.y + j[k].y };
  }
  return out;
}

/** Brain + engine wired like the component, stepping at 60 fps with fake timers. */
class Sim {
  readonly commands: BrainCommand[] = [];
  readonly engine = new MascotEngine(STAGE, seeded(3));
  readonly brain: MascotBrain;
  visible = false;
  prev: World | null = null;
  maxJump = 0;
  maxJumpAt = '';
  actions: string[] = [];
  ragdollFrames = 0;
  frameNo = 0;
  jumps: string[] = [];
  log: string[] = [];
  prevAction = '';

  constructor(rngValue = 0.5) {
    this.brain = new MascotBrain((c) => this.command(c), { rng: () => rngValue, width: () => STAGE.width });
  }

  command(c: BrainCommand): void {
    this.commands.push(c);
    if (c.type === 'play') {
      this.actions.push(c.action);
      this.log.push(`f${this.frameNo}:${c.action}`);
      this.engine.play(c.action, { side: c.side, targetX: c.targetX, blend: c.blend, variant: c.variant });
    } else if (c.type === 'gesture') {
      this.engine.gesture(c.name);
    } else if (c.type === 'say') {
      this.engine.say(c.key);
    } else {
      this.visible = c.value;
      if (!c.value) {
        this.prev = null;
      }
    }
  }

  frames(n: number): void {
    for (let i = 0; i < n; i++) {
      vi.advanceTimersByTime(1000 / 60);
      this.frameNo++;
      this.prevAction = this.engine.currentAction;
      const wasVisible = this.visible;
      const { frame, events } = this.engine.tick(1 / 60);
      for (const e of events) {
        dispatchEngineEvent(this.brain, e);
      }
      if (!this.visible || !wasVisible) {
        this.prev = null;
        continue;
      }
      const cur = worldJoints(frame.pose, frame.joints);
      const rag = this.engine.ragdoll;
      if (rag) {
        // During the fall the drawing IS the physics.
        this.ragdollFrames++;
        for (const k of KEYS) {
          const i2 = RAG_INDEX[k] * 2;
          expect(Math.abs(cur[k].x - rag.pos[i2])).toBeLessThan(1e-9);
          expect(Math.abs(cur[k].y - rag.pos[i2 + 1])).toBeLessThan(1e-9);
        }
      } else if (this.prev) {
        // While dragged the root simply follows the user's pointer; the body must not jump relative to it.
        const dragged = this.engine.currentAction === 'dragged';
        const rdx = dragged ? cur.hip.x - this.prev.hip.x : 0;
        const rdy = dragged ? cur.hip.y - this.prev.hip.y : 0;
        for (const k of KEYS) {
          const d = Math.hypot(cur[k].x - this.prev[k].x - rdx, cur[k].y - this.prev[k].y - rdy);
          if (d > 25) {
            this.jumps.push(`f${this.frameNo} ${this.engine.currentAction} ${k} ${d.toFixed(1)} y=${cur[k].y.toFixed(0)}`);
          }
          if (d > this.maxJump) {
            this.maxJump = d;
            this.maxJumpAt = `${this.engine.currentAction}:${k}:f${this.frameNo}:prev=${this.prevAction}`;
          }
        }
      }
      this.prev = rag ? null : cur;
      if (rag) {
        // Re-seed the reference for the first non-ragdoll frame.
        this.prev = cur;
      }
    }
  }

  until(pred: () => boolean, max = 1200): void {
    for (let i = 0; i < max && !pred(); i++) {
      this.frames(1);
    }
  }

  drag(dx: number, dy: number, steps = 24): void {
    const r = this.engine.root;
    this.brain.dragStart();
    this.engine.dragStart(r.x, r.y - 20);
    for (let i = 1; i <= steps; i++) {
      this.engine.dragMove(r.x + (dx * i) / steps, r.y - 20 + (dy * i) / steps);
      this.frames(1);
    }
    this.engine.dragEnd();
    this.brain.dragEnd();
  }
}

describe('real falls: throw → ragdoll → get up, continuously', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('entrance → idle → throw → tumble → rest → get up → rub head → dust → idle, no joint jumps > 40 px/frame', () => {
    const sim = new Sim();
    sim.brain.start(true, false);
    vi.advanceTimersByTime(TIMING.firstAppearance);
    sim.until(() => sim.brain.state === 'idle');
    sim.frames(30);
    sim.drag(260, -220);
    expect(sim.engine.airborne).toBe(true);
    sim.until(() => sim.brain.state === 'recovering');
    expect(sim.ragdollFrames).toBeGreaterThan(10);
    sim.until(() => sim.brain.state === 'idle');
    expect(sim.brain.state).toBe('idle');
    expect(sim.actions).toEqual(expect.arrayContaining(['getup', 'rub-head', 'dust']));
    expect(sim.maxJump).toBeLessThan(40);
    // Stood up where he fell, on his feet.
    expect(sim.engine.root.y).toBeCloseTo(STAGE.ground - 44, -1);
  });

  it('a hard throw says «ouch», makes him dizzy, and three throws in 20 s make him sulk first', () => {
    const sim = new Sim(0.3);
    sim.brain.start(true, false);
    vi.advanceTimersByTime(TIMING.firstAppearance);
    sim.until(() => sim.brain.state === 'idle');
    // Three throws in a row, grabbing him again while he still lies on the floor.
    for (let n = 0; n < 3; n++) {
      sim.drag(160, -700, 12);
      sim.until(() => sim.engine.ragdoll?.rested === true);
    }
    expect(sim.commands.some((c) => c.type === 'say' && c.key?.startsWith('assistant.ouch.'))).toBe(true);
    sim.until(() => sim.brain.state === 'idle', 2000);
    expect(sim.actions).toContain('sulk');
    expect(sim.actions).toContain('stand-up');
    expect(sim.maxJump).toBeLessThan(40);
  });

  it('slip on a banana peel: a real fall from an idle, then gets up', () => {
    const sim = new Sim();
    sim.brain.start(true, false);
    vi.advanceTimersByTime(TIMING.firstAppearance);
    sim.until(() => sim.brain.state === 'idle');
    sim.brain.clipDone('idle-breathe');
    sim.engine.play('idle-slip');
    let sawBanana = false;
    sim.until(() => {
      sawBanana ||= sim.engine.tick(0).frame.effects.some((e) => e.kind === 'banana');
      return sim.brain.state === 'fallen';
    });
    expect(sawBanana).toBe(true);
    sim.until(() => sim.brain.state === 'idle');
    expect(sim.actions).toContain('getup');
    expect(sim.maxJump).toBeLessThan(40);
  });

  it('nothing flies off him while he is dragged or thrown (no arrows, no speed streaks, no effects at all)', () => {
    const e = new MascotEngine(STAGE, seeded(1));
    e.play('static', { targetX: 600, blend: 0 });
    e.tick(0.2);
    const r = e.root;
    e.dragStart(r.x, r.y - 20);
    const kinds = new Set<string>();
    // Fast yanks (3600 px/s) used to spawn a speed streak every 0.06 s, up to ~270 px long.
    for (let i = 1; i <= 20; i++) {
      e.dragMove(r.x + i * 60, r.y - 20 - i * 30);
      e.tick(1 / 60).frame.effects.forEach((fx) => kinds.add(fx.kind));
    }
    expect([...kinds]).toEqual([]);
    e.dragEnd();
    // In flight until the first impact: still nothing (dust/stars only on landing).
    for (let i = 0; i < 6; i++) {
      e.tick(1 / 60).frame.effects.forEach((fx) => kinds.add(fx.kind));
    }
    expect([...kinds]).toEqual([]);
  });

  it('grab where clicked; release keeps the ragdoll velocities (the swing is the throw)', () => {
    const e = new MascotEngine(STAGE, seeded(5));
    e.play('static', { targetX: 600, blend: 0 });
    e.tick(0.3);
    const f = e.tick(0).frame;
    const hand = { x: f.pose.x + f.joints.rHand.x, y: f.pose.y + f.joints.rHand.y };
    e.dragStart(hand.x, hand.y);
    for (let i = 1; i <= 30; i++) {
      e.dragMove(hand.x + i * 12, hand.y - i * 8);
      e.tick(1 / 60);
    }
    // Held by the hand: the hand is (almost) at the pointer, the body hangs below it.
    const held = e.tick(0).frame;
    const heldHand = { x: held.pose.x + held.joints.rHand.x, y: held.pose.y + held.joints.rHand.y };
    expect(Math.hypot(heldHand.x - (hand.x + 360), heldHand.y - (hand.y - 240))).toBeLessThan(25);
    expect(held.pose.y).toBeGreaterThan(heldHand.y);
    const before = [P.Head, P.Pelvis, P.RHand, P.LFoot].map((i) => e.ragdollVelocity(i));
    e.dragEnd();
    const after = [P.Head, P.Pelvis, P.RHand, P.LFoot].map((i) => e.ragdollVelocity(i));
    expect(after).toEqual(before);
    expect(before[1].x).toBeGreaterThan(100);
    expect(e.airborne).toBe(true);
  });

  it('once up and calm the loop can stop; lying still with nothing to draw is calm too', () => {
    const e = new MascotEngine(STAGE, seeded(4), false);
    e.setLite(true);
    e.play('static', { targetX: 600, blend: 0 });
    e.tick(0.2);
    const r = e.root;
    e.dragStart(r.x, r.y);
    e.dragEnd();
    for (let i = 0; i < 600 && e.ragdoll && !e.ragdoll.rested; i++) {
      e.tick(1 / 60);
    }
    e.tick(0.1);
    expect(e.ragdoll?.rested).toBe(true);
    expect(e.calm).toBe(true);
    expect(e.fps).toBe(0);
  });
});

describe('review fixes: resize, reduced motion, lost hold', () => {
  const ragPoints = (e: MascotEngine): { x: number; y: number }[] => {
    const r = e.ragdoll!;
    return Array.from({ length: 12 }, (_, i) => ({ x: r.pos[i * 2], y: r.pos[i * 2 + 1] }));
  };
  const throwIt = (e: MascotEngine): void => {
    e.play('static', { targetX: 600, blend: 0 });
    e.tick(0.3);
    const r = e.root;
    e.dragStart(r.x, r.y - 20);
    for (let i = 1; i <= 6; i++) {
      e.dragMove(r.x + i * 20, r.y - 20 - i * 25);
      e.tick(1 / 60);
    }
    e.dragEnd();
  };

  it('resize during a fall: lands on the new floor inside the new width', () => {
    const e = new MascotEngine(STAGE, seeded(2));
    throwIt(e);
    e.tick(0.1);
    e.setStage({ ...STAGE, width: 500, height: 600, ground: 598 });
    const events: string[] = [];
    for (let i = 0; i < 900 && !e.ragdoll?.rested; i++) {
      events.push(...e.tick(1 / 60).events.map((ev) => ev.type));
    }
    expect(e.ragdoll?.rested).toBe(true);
    for (const p of ragPoints(e)) {
      expect(p.y).toBeLessThanOrEqual(598 + 1e-9);
      expect(p.x).toBeGreaterThanOrEqual(0);
      expect(p.x).toBeLessThanOrEqual(500);
    }
    expect(Math.max(...ragPoints(e).map((p) => p.y))).toBeGreaterThan(590);
    expect(events.filter((t) => t === 'rested')).toHaveLength(1);
  });

  it('resize while lying: re-settles on the new floor (lower → falls, higher → lifted), no second «rested»', () => {
    const e = new MascotEngine(STAGE, seeded(2));
    throwIt(e);
    for (let i = 0; i < 900 && !e.ragdoll?.rested; i++) {
      e.tick(1 / 60);
    }
    const before = ragPoints(e);
    // Lower floor: he drops onto it, continuously (no jump bigger than a physics step).
    e.setStage({ ...STAGE, height: 900, ground: 898 });
    const events: string[] = [];
    let prev = before;
    for (let i = 0; i < 900 && !e.ragdoll?.rested; i++) {
      events.push(...e.tick(1 / 60).events.map((ev) => ev.type));
      const cur = ragPoints(e);
      for (let k = 0; k < cur.length; k++) {
        expect(Math.hypot(cur[k].x - prev[k].x, cur[k].y - prev[k].y)).toBeLessThan(40);
      }
      prev = cur;
    }
    expect(Math.max(...ragPoints(e).map((p) => p.y))).toBeGreaterThan(890);
    expect(events).not.toContain('rested');
    // Higher floor and a narrower window: lifted onto it and kept inside.
    e.setStage({ ...STAGE, width: 400, height: 500, ground: 498 });
    for (let i = 0; i < 900 && !e.ragdoll?.rested; i++) {
      e.tick(1 / 60);
    }
    for (const p of ragPoints(e)) {
      expect(p.y).toBeLessThanOrEqual(498 + 1e-9);
      expect(p.x).toBeLessThanOrEqual(400);
    }
  });

  it('reduced motion: drag still moves him, release eases him to standing at the drop spot within 0.2 s — no tumble', () => {
    const e = new MascotEngine(STAGE, seeded(2), true);
    e.play('static', { targetX: 600, blend: 0 });
    e.tick(0.3);
    const r = e.root;
    e.dragStart(r.x, r.y - 20);
    for (let i = 1; i <= 10; i++) {
      e.dragMove(r.x - i * 15, r.y - 20 - i * 20);
      e.tick(1 / 60);
    }
    expect(e.root.y).toBeLessThan(r.y - 100);
    const dropX = e.root.x;
    e.dragEnd();
    expect(e.ragdoll).toBeNull();
    const events: string[] = [];
    let prev = e.tick(0).frame;
    for (let i = 0; i < 12; i++) {
      const { frame, events: ev } = e.tick(1 / 60);
      events.push(...ev.map((x) => x.type));
      for (const k of ['head', 'lFoot', 'rHand'] as const) {
        const d = Math.hypot(frame.pose.x + frame.joints[k].x - prev.pose.x - prev.joints[k].x, frame.pose.y + frame.joints[k].y - prev.pose.y - prev.joints[k].y);
        expect(d).toBeLessThan(40);
      }
      prev = frame;
    }
    expect(events).not.toContain('fell');
    expect(e.root.y).toBeCloseTo(STAGE.ground - 44, 0);
    expect(Math.abs(e.root.x - dropX)).toBeLessThan(1);
    expect(e.calm).toBe(true);
  });

  it('reduced motion brain: after the drop he is just standing — no get-up, dizzy or sulk', () => {
    vi.useFakeTimers();
    try {
      const commands: BrainCommand[] = [];
      const brain = new MascotBrain((c) => commands.push(c), { rng: () => 0.5, width: () => 1200 });
      brain.start(true, true);
      for (let i = 0; i < 4; i++) {
        brain.dragStart();
        brain.dragEnd();
        brain.fell();
        brain.rested(3000);
      }
      vi.advanceTimersByTime(10_000);
      expect(brain.state).toBe('static');
      const plays = commands.filter((c) => c.type === 'play').map((c) => (c as { action: string }).action);
      for (const a of ['getup', 'sulk', 'dizzy', 'rub-head']) {
        expect(plays).not.toContain(a);
      }
    } finally {
      vi.useRealTimers();
    }
  });

  it('a cancelled hold drops him without throw velocity', () => {
    const e = new MascotEngine(STAGE, seeded(2));
    e.play('static', { targetX: 600, blend: 0 });
    e.tick(0.3);
    const r = e.root;
    e.dragStart(r.x, r.y - 20);
    for (let i = 1; i <= 8; i++) {
      e.dragMove(r.x + i * 40, r.y - 20);
      e.tick(1 / 60);
    }
    expect(Math.abs(e.ragdollVelocity(P.Pelvis).x)).toBeGreaterThan(200);
    e.dragCancel();
    expect(e.held).toBe(false);
    expect(e.ragdollVelocity(P.Pelvis)).toEqual({ x: 0, y: 0 });
    expect(e.airborne).toBe(true);
  });
});
