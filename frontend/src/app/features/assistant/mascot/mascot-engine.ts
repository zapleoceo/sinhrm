import {
  ActionName,
  Clip,
  ClipContext,
  ClipEnv,
  ClipEvent,
  Gesture,
  GestureName,
  PropsFrame,
  Stage,
  airborneClip,
  createClip,
  createGesture,
  ease,
} from './animations';
import {
  Effect,
  spawnBang,
  spawnDoodle,
  spawnDust,
  spawnRing,
  spawnRope,
  spawnSparkles,
  spawnStars,
  spawnStreak,
  spawnSweat,
  spawnZ,
  stepEffects,
} from './effects';
import { EyeShape, Expression, NEUTRAL, blinkAmount, squintFor } from './face';
import { normalizeAngle } from './ik';
import { Body, PHYSICS_DT, World, accumulate, defaultWorld, launchSpeed, physicsStep } from './physics';
import { Joints, Pose, STAND, STAND_HIP, Vec, forwardKinematics, lerpPose } from './skeleton';
import { PoseSprings, applySprings, initPoseSprings, stepPoseSprings } from './springs';

export type EngineEvent = { type: 'clipDone'; action: ActionName } | { type: 'landed'; speed: number } | { type: 'offscreen' };

export interface ShadowState {
  x: number;
  y: number;
  rx: number;
  opacity: number;
}

export interface BubbleState {
  text: string;
  chars: number;
}

/** Everything the renderer needs for one drawn frame. */
export interface Frame {
  pose: Pose;
  joints: Joints;
  expr: Expression;
  /** Where the eyes look (unit-ish world direction). */
  eyeDir: Vec;
  /** Pointer in world coordinates (for pupils of the circle), null when unknown. */
  pointer: Vec | null;
  blink: number;
  squint: number;
  morph: number;
  scale: number;
  effects: readonly Effect[];
  shadow: ShadowState | null;
  props: PropsFrame;
  thinking: boolean;
  /** Voice level 0..1 while listening (sound waves at the ear), null otherwise. */
  listening: number | null;
  bubble: BubbleState | null;
  boilSeed: number;
  time: number;
}

export interface PlayOptions {
  side?: 'left' | 'right';
  targetX?: number;
  blend?: number;
}

interface GestureState {
  g: Gesture;
  t: number;
  weight: number;
  fading: boolean;
}

const NO_PROPS: PropsFrame = { rope: null, book: null, balls: null };
const TYPE_SPEED = 42;
/** Line boil re-roll interval (~8 Hz). */
const BOIL_EVERY = 0.125;
/** Clips that need 60 fps (fast motion); calm idles render at 30, sleep at 10. */
/** Gestures that animate continuously at 60 fps. */
const FAST_GESTURES: ReadonlySet<GestureName> = new Set<GestureName>(['celebrate', 'listen']);
const FAST_ACTIONS: ReadonlySet<ActionName> = new Set<ActionName>([
  'enter-peek',
  'enter-climb',
  'enter-jump',
  'enter-drop',
  'enter-walk',
  'enter-sneak',
  'enter-rope',
  'enter-gopher',
  'enter-slide',
  'peek-out',
  'exit-run',
  'exit-jump',
  'exit-slide',
  'exit-wave',
  'exit-peek',
  'curl',
  'unfold',
  'orb-pop',
  'wake',
  'land',
  'dizzy',
  'hold-on',
  'dragged',
  'airborne',
]);
/** Clips that stand still: once settled the loop may stop (render on demand only). */
const STILL_ACTIONS: ReadonlySet<ActionName> = new Set<ActionName>(['orb', 'static']);

/**
 * The living figure without the DOM: plays clips, flies the physics body, filters joints through springs,
 * layers gestures, spawns effects and produces a Frame per tick. Deterministic for a given rng and dt sequence.
 */
export class MascotEngine {
  private stage: Stage;
  private readonly rng: () => number;
  private reduced: boolean;
  private lite = false;
  private clip: Clip;
  private clipT = 0;
  private doneSent = false;
  private blendFrom: Pose | null = null;
  private blendFromMorph = 0;
  private blendT = 0;
  private blendDur = 0;
  private mode: 'clip' | 'physics' | 'drag' = 'clip';
  private physicsKind: 'launch' | 'hop' = 'launch';
  private body: Body = { x: 0, y: 0, vx: 0, vy: 0, rot: 0, spin: 0 };
  private world: World;
  private maxImpact = 0;
  private springs: PoseSprings;
  private displayed: Pose;
  private joints: Joints;
  private lastRoot: Vec | null = null;
  private lastVel: Vec = { x: 0, y: 0 };
  private gestureState: GestureState | null = null;
  private effects: Effect[] = [];
  private ambientT = 0;
  private streakT = 0;
  private time = 0;
  private acc = 0;
  private brows = 0;
  private eyeDir: Vec = { x: 0, y: 0.1 };
  private eyeOverride: { shape: EyeShape; until: number } | null = null;
  private blinkStart = -1;
  private blinkNext = 2;
  private pointerPos: Vec | null = null;
  private glanceAt: { p: Vec; until: number } | null = null;
  private bubble: { text: string; t: number } | null = null;
  private boilSeed = 0;
  private boilT = 0;
  private morph = 0;
  private scale = 1;
  private dragPos: Vec | null = null;
  private grab: Vec = { x: 0, y: 0 };
  private dragSamples: { x: number; y: number; t: number }[] = [];
  private props: PropsFrame = NO_PROPS;
  private thinking = false;
  private listening = false;
  private audioLevel = 0;
  private expr: Expression = { ...NEUTRAL };
  private readonly events: EngineEvent[] = [];

  constructor(stage: Stage, rng: () => number = Math.random, reducedMotion = false) {
    this.stage = stage;
    this.rng = rng;
    this.reduced = reducedMotion;
    this.world = defaultWorld(stage.width, stage.ground - STAND_HIP);
    this.displayed = { ...STAND, x: -200, y: stage.ground - STAND_HIP };
    this.springs = initPoseSprings(this.displayed);
    this.joints = forwardKinematics(this.displayed);
    this.clip = createClip('static', this.context({ targetX: -200 }));
  }

  get currentAction(): ActionName {
    return this.clip.action;
  }

  get root(): Vec {
    return { x: this.displayed.x, y: this.displayed.y };
  }

  /** World position of the head centre. */
  get head(): Vec {
    return { x: this.displayed.x + this.joints.head.x, y: this.displayed.y + this.joints.head.y };
  }

  get airborne(): boolean {
    return this.mode === 'physics' && this.physicsKind === 'launch';
  }

  setStage(stage: Stage): void {
    this.stage = stage;
    this.world = { ...this.world, width: stage.width };
  }

  setReducedMotion(on: boolean): void {
    this.reduced = on;
  }

  /** Lite animation: no line boil and no particles (the loop also caps at 30 fps). */
  setLite(on: boolean): void {
    this.lite = on;
    if (on) {
      this.effects = [];
    }
  }

  /** Nothing is moving: a still clip, no blend, no flight, effects, gesture or bubble. The loop may stop. */
  get calm(): boolean {
    const blending = this.blendFrom !== null && this.blendT < this.blendDur;
    return STILL_ACTIONS.has(this.clip.action) && !blending && this.mode === 'clip' && this.effects.length === 0 && this.gestureState === null && this.bubble === null;
  }

  /** Render rate this moment needs: 0 when calm, 10 asleep, 30 calm idle, 60 fast motion (30 max in lite). */
  get fps(): number {
    if (this.calm) {
      return 0;
    }
    const speed = Math.hypot(this.lastVel.x, this.lastVel.y);
    const fast = this.mode !== 'clip' || speed > 120 || FAST_ACTIONS.has(this.clip.action) || (this.gestureState !== null && FAST_GESTURES.has(this.gestureState.g.name));
    const fps = this.clip.action === 'sleep' ? 10 : fast ? 60 : 30;
    return this.lite ? Math.min(30, fps) : fps;
  }

  /** Blink now (used by the on-demand blink timer while the loop is stopped). */
  blink(): void {
    this.blinkStart = this.time;
    this.blinkNext = this.time + 3;
  }

  play(action: ActionName, options: PlayOptions = {}): void {
    const from: Pose = { ...this.displayed, rot: normalizeAngle(this.displayed.rot) };
    this.clip = createClip(action, this.context({ ...options, from }));
    this.clipT = 0;
    this.doneSent = false;
    this.ambientT = 0;
    if (action === 'dragged') {
      this.mode = 'drag';
    } else if (!(this.mode === 'physics' && this.physicsKind === 'hop')) {
      this.mode = 'clip';
    }
    const blend = this.reduced ? 0 : (options.blend ?? this.clip.blend);
    this.startBlend(blend);
  }

  gesture(name: GestureName | null, target: Vec | null = null): void {
    if (name === null) {
      if (this.gestureState) {
        this.gestureState.fading = true;
      }
      return;
    }
    const weight = this.gestureState && !this.gestureState.fading ? this.gestureState.weight : 0;
    this.gestureState = { g: createGesture(name, target), t: 0, weight: this.reduced ? 1 : weight, fading: false };
  }

  get gestureName(): GestureName | null {
    return this.gestureState && !this.gestureState.fading ? this.gestureState.g.name : null;
  }

  /** Microphone level 0..1 (read by the caller from the recorder once per frame). */
  setAudioLevel(level: number): void {
    this.audioLevel = Math.max(0, Math.min(1, level));
  }

  say(text: string | null): void {
    this.bubble = text ? { text, t: 0 } : null;
  }

  pointer(p: Vec | null): void {
    this.pointerPos = p;
  }

  /** Looks at a point for a while (a new toast, the confirmation card). */
  glance(p: Vec, seconds = 1.5): void {
    this.glanceAt = { p, until: this.time + seconds };
  }

  /** Something new appeared (toast, dialog): a startled glance. */
  notice(p: Vec): void {
    this.glance(p, 1.8);
    this.add(spawnBang());
  }

  /** Fast scrolling shakes him (springs get a kick). */
  jolt(speed: number): void {
    if (this.reduced) {
      return;
    }
    const k = Math.max(-6, Math.min(6, speed / 800));
    this.springs.torso.v += k * 0.6;
    this.springs.head.v -= k;
    this.springs.lShoulder.v += k * 2;
    this.springs.rShoulder.v -= k * 2;
  }

  dragStart(x: number, y: number, tMs: number): void {
    this.grab = { x: this.displayed.x - x, y: this.displayed.y - y };
    this.dragPos = { x: this.displayed.x, y: this.displayed.y };
    this.dragSamples = [{ x, y, t: tMs }];
    this.play('dragged');
  }

  dragMove(x: number, y: number, tMs: number): void {
    this.dragPos = { x: x + this.grab.x, y: y + this.grab.y };
    this.dragSamples.push({ x, y, t: tMs });
    while (this.dragSamples.length > 2 && tMs - this.dragSamples[0].t > 120) {
      this.dragSamples.shift();
    }
  }

  /** Releases the drag: he is thrown with the pointer velocity. */
  dragEnd(): void {
    const v = this.dragVelocity();
    const pos = this.dragPos ?? this.root;
    this.dragPos = null;
    this.launch({ x: pos.x, y: pos.y, vx: v.x, vy: v.y, rot: this.displayed.rot, spin: Math.max(-16, Math.min(16, v.x * 0.008)) }, true, true);
  }

  tick(dt: number): { frame: Frame; events: EngineEvent[] } {
    const r = accumulate(this.acc, dt);
    this.acc = r.acc;
    for (let i = 0; i < r.steps; i++) {
      this.step(PHYSICS_DT);
    }
    const events = this.events.splice(0, this.events.length);
    return { frame: this.frame(), events };
  }

  /* ───────────── internals ───────────── */

  private context(o: PlayOptions & { from?: Pose }): ClipContext {
    const from = o.from ?? this.displayed;
    return {
      stage: this.stage,
      from,
      side: o.side ?? (from.x < this.stage.width / 2 ? 'left' : 'right'),
      targetX: o.targetX ?? from.x,
      rng: this.rng,
    };
  }

  private startBlend(blend: number): void {
    this.blendT = 0;
    this.blendDur = blend;
    this.blendFromMorph = this.morph;
    if (blend > 0) {
      this.blendFrom = { ...this.displayed, rot: normalizeAngle(this.displayed.rot) };
    } else {
      this.blendFrom = null;
      const first = this.clip.sample(0, this.env());
      this.springs = initPoseSprings(first.pose);
      this.lastRoot = null;
      this.morph = first.morph ?? 0;
    }
  }

  private env(): ClipEnv {
    const v = this.mode === 'drag' ? this.dragVelocity() : { x: this.body.vx, y: this.body.vy };
    return { vx: v.x, vy: v.y, spin: this.body.spin, pointer: this.mode === 'drag' ? this.dragPos : this.pointerPos };
  }

  private dragVelocity(): Vec {
    const s = this.dragSamples;
    if (s.length < 2) {
      return { x: 0, y: 0 };
    }
    const a = s[0];
    const b = s[s.length - 1];
    const dt = Math.max(16, b.t - a.t) / 1000;
    const clamp = (v: number): number => Math.max(-2600, Math.min(2600, v));
    return { x: clamp((b.x - a.x) / dt), y: clamp((b.y - a.y) / dt) };
  }

  private launch(body: Body, walls: boolean, floor: boolean): void {
    this.body = body;
    this.world = { ...defaultWorld(this.stage.width, this.stage.ground - STAND_HIP), walls, floor };
    this.mode = 'physics';
    this.physicsKind = 'launch';
    this.maxImpact = 0;
    this.clip = airborneClip(this.displayed);
    this.clipT = 0;
    this.doneSent = true;
    this.startBlend(this.reduced ? 0 : 0.12);
  }

  private hop(): void {
    if (this.mode !== 'clip' || this.reduced) {
      return;
    }
    this.body = { x: this.displayed.x, y: this.displayed.y, vx: 0, vy: launchSpeed(34), rot: 0, spin: 0 };
    this.world = { ...defaultWorld(this.stage.width, this.displayed.y), ceiling: null };
    this.mode = 'physics';
    this.physicsKind = 'hop';
  }

  private worldPoint(anchor: ClipEvent['at']): Vec {
    const j = this.joints;
    const p = this.displayed;
    switch (anchor) {
      case 'head':
        return { x: p.x + j.head.x, y: p.y + j.head.y };
      case 'rHand':
        return { x: p.x + j.rHand.x, y: p.y + j.rHand.y };
      case 'hip':
        return { x: p.x, y: p.y + 4 };
      default:
        return { x: p.x + (j.lFoot.x + j.rFoot.x) / 2, y: p.y + Math.max(j.lFoot.y, j.rFoot.y) };
    }
  }

  private add(...list: Effect[]): void {
    if (this.reduced || this.lite) {
      return;
    }
    this.effects.push(...list);
  }

  private handleEvent(e: ClipEvent, env: ClipEnv): void {
    const at = e.point ?? this.worldPoint(e.at);
    switch (e.type) {
      case 'launch': {
        const start = this.clip.sample(e.t, env).pose;
        this.launch({ x: start.x, y: start.y, vx: e.vx ?? 0, vy: e.vy ?? 0, rot: start.rot, spin: e.spin ?? 0 }, e.walls ?? true, e.floor ?? true);
        break;
      }
      case 'hop':
        this.hop();
        break;
      case 'dust':
        this.add(...spawnDust(at.x, e.at === 'rHand' ? at.y : this.stage.ground, e.strength ?? 0.4, this.rng));
        break;
      case 'sparkle':
        this.add(...spawnSparkles(at.x, at.y - 10, this.rng));
        break;
      case 'sweat':
        this.add(spawnSweat());
        break;
      case 'bang':
        this.add(spawnBang());
        break;
      case 'stars':
        this.add(...spawnStars());
        break;
      case 'ring':
        this.add(spawnRing(at.x, at.y));
        break;
      case 'doodle':
        if (e.shape) {
          this.add(spawnDoodle(at.x, at.y, e.shape, e.size ?? 12));
        }
        break;
      case 'rope':
        if (e.rope) {
          this.add(spawnRope(e.rope.ax, e.rope.ay, e.rope.len, e.rope.angle, e.rope.av));
        }
        break;
    }
  }

  private onImpact(speed: number): void {
    this.maxImpact = Math.max(this.maxImpact, speed);
    if (!this.reduced) {
      this.springs.squash.v -= Math.min(speed, 2400) * 0.0026;
    }
    const feet = this.worldPoint('feet');
    this.add(...spawnDust(feet.x, this.world.groundHip + STAND_HIP, speed / 1500, this.rng));
    if (speed > 1300) {
      this.eyeOverride = { shape: 'x', until: this.time + 0.45 };
    }
  }

  private step(h: number): void {
    this.time += h;
    const prevT = this.clipT;
    this.clipT += h;
    let env = this.env();
    if (this.mode !== 'physics') {
      for (const e of this.clip.events) {
        if (prevT < e.t && e.t <= this.clipT) {
          this.handleEvent(e, env);
          if (this.airborne) {
            break;
          }
        }
      }
      env = this.env();
    }

    let physicsRoot: Body | null = null;
    if (this.mode === 'physics') {
      const res = physicsStep(this.body, h, this.world);
      this.body = res.body;
      physicsRoot = res.body;
      for (const ev of res.events) {
        if (ev.type === 'impact') {
          this.onImpact(ev.speed);
        } else if (ev.type === 'offscreen') {
          this.events.push({ type: 'offscreen' });
          this.mode = 'clip';
        }
      }
      if (res.settled) {
        this.mode = 'clip';
        this.body = { ...this.body, rot: normalizeAngle(this.body.rot) };
        if (this.physicsKind === 'launch') {
          this.displayed = { ...this.displayed, x: this.body.x, y: this.body.y, rot: this.body.rot };
          this.events.push({ type: 'landed', speed: this.maxImpact });
          this.play('land', { blend: Math.abs(this.body.rot) > 0.3 ? 0.3 : 0.06 });
        }
      }
      env = this.env();
    }

    const frame = this.clip.sample(this.clipT, env);
    let target: Pose = { ...frame.pose };
    if (physicsRoot && (this.mode === 'physics' || this.clip.physics)) {
      target.x = physicsRoot.x;
      target.y = physicsRoot.y;
      if (this.physicsKind === 'launch') {
        target.rot = physicsRoot.rot;
      }
    } else if (this.clip.physics) {
      target.x = this.body.x;
      target.y = this.body.y;
      target.rot = this.body.rot;
    }

    if (!this.doneSent && this.clipT >= this.clip.duration) {
      this.doneSent = true;
      this.events.push({ type: 'clipDone', action: this.clip.action });
    }

    let morph = frame.morph ?? 0;
    if (this.blendFrom && this.blendT < this.blendDur) {
      this.blendT += h;
      const u = ease.inOut(Math.min(1, this.blendT / this.blendDur));
      target = lerpPose(this.blendFrom, target, u);
      morph = this.blendFromMorph + (morph - this.blendFromMorph) * u;
    }
    this.morph = morph;
    this.scale = frame.scale ?? 1;

    const gesture = this.applyGesture(target, h);

    // Root acceleration drives inertia of the loose joints.
    let ax = 0;
    let ay = 0;
    if (this.lastRoot) {
      const vx = (target.x - this.lastRoot.x) / h;
      const vy = (target.y - this.lastRoot.y) / h;
      if (Math.abs(vx) > 6000 || Math.abs(vy) > 6000) {
        this.lastVel = { x: 0, y: 0 };
      } else {
        ax = (vx - this.lastVel.x) / h;
        ay = (vy - this.lastVel.y) / h;
        this.lastVel = { x: vx, y: vy };
      }
    }
    this.lastRoot = { x: target.x, y: target.y };
    this.springs = this.reduced ? initPoseSprings(target) : stepPoseSprings(this.springs, target, h, ax * target.facing, ay);
    this.displayed = applySprings(target, this.springs);
    this.joints = forwardKinematics(this.displayed);

    this.props = { ...NO_PROPS, ...frame.props };
    this.stepEffects(h);
    this.stepFace(h, frame.expr, frame.eyeDir, gesture);
  }

  private applyGesture(target: Pose, h: number): { expr?: Partial<Expression>; eyeDir?: Vec; weight: number } {
    const g = this.gestureState;
    this.thinking = false;
    this.listening = false;
    if (!g) {
      return { weight: 0 };
    }
    const prevT = g.t;
    g.t += h;
    if (g.t >= g.g.duration) {
      g.fading = true;
    }
    const goal = g.fading ? 0 : 1;
    g.weight = this.reduced ? goal : g.weight + Math.sign(goal - g.weight) * Math.min(Math.abs(goal - g.weight), h / (g.fading ? 0.3 : 0.2));
    for (const e of g.g.events) {
      if (prevT < e.t && e.t <= g.t) {
        this.handleEvent(e, this.env());
      }
    }
    const gf = g.g.sample(g.t, target);
    const w = g.weight;
    const record = target as unknown as Record<string, number>;
    for (const [key, value] of Object.entries(gf.pose)) {
      if (typeof value === 'number' && key !== 'facing') {
        record[key] = record[key] + (value - record[key]) * w;
      }
    }
    if (g.fading && g.weight <= 0) {
      this.gestureState = null;
    }
    this.thinking = !!gf.thinking && w > 0.3;
    this.listening = !!gf.listening && w > 0.3;
    return w > 0.5 ? { expr: gf.expr, eyeDir: gf.eyeDir, weight: w } : { weight: w };
  }

  private stepEffects(h: number): void {
    this.effects = stepEffects(this.effects, h);
    const head = this.head;
    if (this.clip.ambient && !this.reduced) {
      this.ambientT += h;
      if (this.ambientT >= this.clip.ambient.every) {
        this.ambientT = 0;
        this.add(spawnZ(head.x + this.displayed.facing * 10, head.y - 12, this.rng));
      }
    }
    const speed = Math.hypot(this.lastVel.x, this.lastVel.y);
    if (speed > 650 && !this.reduced && this.morph < 0.5) {
      this.streakT += h;
      if (this.streakT >= 0.06) {
        this.streakT = 0;
        this.add(spawnStreak(this.displayed.x, this.displayed.y - 20, this.lastVel.x, this.lastVel.y, this.rng));
      }
    }
  }

  private stepFace(h: number, frameExpr: Partial<Expression> | undefined, frameEye: Vec | undefined, gesture: { expr?: Partial<Expression>; eyeDir?: Vec }): void {
    const expr: Expression = { ...NEUTRAL, ...frameExpr, ...gesture.expr };
    if (this.eyeOverride && this.time < this.eyeOverride.until) {
      expr.eyes = this.eyeOverride.shape;
    }
    if (this.bubble) {
      this.bubble.t += h;
      const typing = this.bubble.t * TYPE_SPEED < this.bubble.text.length;
      if (typing && !gesture.expr?.mouth) {
        expr.mouth = 'open';
        expr.talk = 0.3 + 0.7 * Math.abs(Math.sin(this.time * 14));
      }
    }
    this.brows += (expr.brows - this.brows) * Math.min(1, h / 0.06);
    this.expr = { ...expr, brows: this.brows };

    const head = this.head;
    let goal: Vec = gesture.eyeDir ?? frameEye ?? { x: 0, y: 0.1 };
    if (!gesture.eyeDir && !frameEye) {
      const look = this.glanceAt && this.time < this.glanceAt.until ? this.glanceAt.p : this.pointerPos;
      if (look) {
        const dx = look.x - head.x;
        const dy = look.y - head.y;
        const len = Math.hypot(dx, dy) || 1;
        const m = Math.min(1, len / 160);
        goal = { x: (dx / len) * m, y: (dy / len) * m };
      }
    }
    const k = Math.min(1, h / 0.07);
    this.eyeDir = { x: this.eyeDir.x + (goal.x - this.eyeDir.x) * k, y: this.eyeDir.y + (goal.y - this.eyeDir.y) * k };

    if (this.time >= this.blinkNext) {
      this.blinkStart = this.time;
      this.blinkNext = this.time + (this.rng() < 0.2 ? 0.28 : 2 + this.rng() * 4);
    }
    if (!this.reduced && !this.lite) {
      this.boilT += h;
      if (this.boilT >= BOIL_EVERY) {
        this.boilT = 0;
        this.boilSeed++;
      }
    }
  }

  private frame(): Frame {
    const p = this.displayed;
    const j = this.joints;
    const head = this.head;
    const pointerDist = this.pointerPos ? Math.hypot(this.pointerPos.x - head.x, this.pointerPos.y - head.y) : Infinity;
    return {
      pose: p,
      joints: j,
      expr: this.expr,
      eyeDir: this.eyeDir,
      pointer: this.pointerPos,
      blink: blinkAmount(this.time, this.blinkStart),
      squint: squintFor(pointerDist),
      morph: this.morph,
      scale: this.scale,
      effects: this.effects,
      shadow: this.shadow(),
      props: this.props,
      thinking: this.thinking,
      listening: this.listening ? this.audioLevel : null,
      bubble: this.bubble ? { text: this.bubble.text, chars: Math.min(this.bubble.text.length, Math.floor(this.bubble.t * TYPE_SPEED)) } : null,
      boilSeed: this.boilSeed,
      time: this.time,
    };
  }

  private shadow(): ShadowState | null {
    const p = this.displayed;
    const j = this.joints;
    const seat = this.stage.seat;
    const onSeat = seat !== null && Math.abs(p.x - seat.x) < 60 && p.y < seat.y + 10 && p.y > seat.y - 200;
    const surface = onSeat && seat ? seat.y : this.stage.ground;
    const orbBottom = p.y + j.head.y + 16;
    const feet = p.y + Math.max(j.lFoot.y, j.rFoot.y);
    const lowest = this.morph > 0.5 ? orbBottom : feet;
    const height = surface - lowest;
    if (height < -6 || (this.morph > 0.5 && !onSeat && Math.abs(surface - orbBottom) > 40)) {
      return null;
    }
    const f = Math.max(0.15, Math.min(1, 1 - height / 260));
    const x = this.morph > 0.5 ? p.x + j.head.x : p.x + (j.lFoot.x + j.rFoot.x) / 2;
    return { x, y: surface, rx: (13 + 10 * f) * (1 - 0.3 * this.morph), opacity: 0.2 * f };
  }
}
