import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { of } from 'rxjs';
import { AuthService } from '../../../core/auth/auth.service';
import { AssistantSettings, ENABLED_KEY } from '../assistant-settings';
import { AssistantService } from '../assistant.service';
import { Stage } from './animations';
import { AssistantMascot, prefersLite } from './assistant-mascot';
import { MascotEngine } from './mascot-engine';
import { FrameClock, MASCOT_FRAME_CLOCK, MascotLoop } from './mascot-loop';
import { PHYSICS_DT } from './physics';
import { KIN_STATS } from './skeleton';

const STAGE: Stage = { width: 1200, height: 800, ground: 798, seat: null, corner: { x: 1166, y: 766 } };

/** Fake rAF: callbacks run only when the test says so. */
class FakeClock implements FrameClock {
  private next = 1;
  readonly queue = new Map<number, (t: number) => void>();
  requests = 0;
  request(cb: (t: number) => void): number {
    this.requests++;
    const id = this.next++;
    this.queue.set(id, cb);
    return id;
  }
  cancel(handle: number): void {
    this.queue.delete(handle);
  }
  /** Runs one vsync at time t (ms). */
  flush(t: number): void {
    const pending = [...this.queue.values()];
    this.queue.clear();
    for (const cb of pending) {
      cb(t);
    }
  }
  get pending(): number {
    return this.queue.size;
  }
}

describe('MascotLoop budget', () => {
  it('does nothing until enabled, stops when disabled', () => {
    const clock = new FakeClock();
    const frame = vi.fn(() => 30);
    const loop = new MascotLoop(clock, frame);
    loop.kick();
    expect(clock.requests).toBe(0);
    loop.setEnabled(true);
    expect(clock.pending).toBe(1);
    loop.setEnabled(false);
    expect(clock.pending).toBe(0);
  });

  it('renders calm idles at 30 fps and fast motion at 60 fps (skips vsyncs)', () => {
    const clock = new FakeClock();
    let wanted = 30;
    const frame = vi.fn(() => wanted);
    const loop = new MascotLoop(clock, frame);
    loop.setEnabled(true);
    for (let i = 0; i <= 60; i++) {
      clock.flush(i * (1000 / 60));
    }
    expect(frame.mock.calls.length).toBeGreaterThanOrEqual(29);
    expect(frame.mock.calls.length).toBeLessThanOrEqual(32);
    frame.mockClear();
    wanted = 60;
    for (let i = 61; i <= 121; i++) {
      clock.flush(i * (1000 / 60));
    }
    expect(frame.mock.calls.length).toBeGreaterThanOrEqual(58);
  });

  it('sleeps at 10 fps', () => {
    const clock = new FakeClock();
    const frame = vi.fn(() => 10);
    const loop = new MascotLoop(clock, frame);
    loop.setEnabled(true);
    for (let i = 0; i <= 120; i++) {
      clock.flush(i * (1000 / 60));
    }
    expect(frame.mock.calls.length).toBeLessThanOrEqual(22);
  });

  it('stops by itself when calm; a poke runs it briefly with one pending frame at most', () => {
    const clock = new FakeClock();
    const frame = vi.fn(() => 0);
    const loop = new MascotLoop(clock, frame);
    loop.setEnabled(true);
    clock.flush(0);
    expect(loop.running).toBe(false);
    expect(clock.pending).toBe(0);
    loop.poke(300, 1000);
    loop.poke(300, 1001);
    loop.poke(300, 1002);
    expect(clock.pending).toBe(1);
    for (let t = 1016; t < 1400; t += 16) {
      clock.flush(t);
    }
    expect(clock.pending).toBe(0);
  });

  it('reports persistently slow frames once (→ lite mode)', () => {
    const clock = new FakeClock();
    const slow = vi.fn();
    const loop = new MascotLoop(clock, () => 60, slow);
    loop.setEnabled(true);
    for (let i = 0; i < 200; i++) {
      clock.flush(i * 40);
    }
    expect(slow).toHaveBeenCalledTimes(1);
  });
});

describe('engine budget', () => {
  it('one fixed physics+pose step stays well under 0.2 ms', () => {
    const e = new MascotEngine(STAGE, () => 0.5);
    e.play('enter-walk', { side: 'left', targetX: 600 });
    e.tick(1);
    const actions = ['idle-breathe', 'idle-juggle', 'exit-door'] as const;
    const results: number[] = [];
    for (const a of actions) {
      e.play(a, { side: 'left', targetX: 600 });
      const n = 1500;
      const t0 = performance.now();
      for (let i = 0; i < n; i++) {
        e.tick(PHYSICS_DT);
      }
      results.push((performance.now() - t0) / n);
    }
    // Typical: ~0.02–0.05 ms per step on a laptop; the bound leaves room for slow CI runners.
    expect(Math.max(...results)).toBeLessThan(0.2);
  });

  it('the still "off" circle and reduced-motion pose are calm (0 fps); sleep asks for 10, fast motion for 60, lite caps at 30', () => {
    const e = new MascotEngine(STAGE, () => 0.5);
    e.play('orb', { blend: 0 });
    e.tick(0.5);
    expect(e.calm).toBe(true);
    expect(e.fps).toBe(0);
    e.play('static', { targetX: 600, blend: 0 });
    e.tick(0.5);
    expect(e.fps).toBe(0);
    e.play('sleep');
    e.tick(0.5);
    expect(e.fps).toBe(10);
    e.play('exit-door');
    e.tick(0.1);
    expect(e.fps).toBe(60);
    e.setLite(true);
    expect(e.fps).toBe(30);
  });

  it('lite mode spawns no particles', () => {
    const e = new MascotEngine(STAGE, () => 0.5);
    e.setLite(true);
    e.play('enter-drop', { targetX: 400 });
    let n = 0;
    for (let t = 0; t < 4; t += 1 / 30) {
      n += e.tick(1 / 30).frame.effects.length;
    }
    expect(n).toBe(0);
  });

  it('prefers lite on weak machines and with Save-Data', () => {
    expect(prefersLite({ hardwareConcurrency: 4 })).toBe(true);
    expect(prefersLite({ hardwareConcurrency: 8, connection: { saveData: true } })).toBe(true);
    expect(prefersLite({ hardwareConcurrency: 12 })).toBe(false);
    expect(prefersLite(undefined)).toBe(false);
  });
});

describe('AssistantMascot schedules no rAF when there is nothing to animate', () => {
  let clock: FakeClock;

  beforeEach(() => {
    localStorage.clear();
    vi.useFakeTimers();
    clock = new FakeClock();
  });

  afterEach(() => vi.useRealTimers());

  async function mount(): Promise<void> {
    TestBed.configureTestingModule({
      imports: [AssistantMascot, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [
        provideRouter([]),
        { provide: MASCOT_FRAME_CLOCK, useValue: clock },
        { provide: AuthService, useValue: { user: signal({ id: 1 }) } },
        { provide: AssistantService, useValue: { status: () => of({ available: true, reason: null, mcp_url: '' }) } },
      ],
    });
    const fixture = TestBed.createComponent(AssistantMascot);
    fixture.detectChanges();
    await vi.advanceTimersByTimeAsync(10);
  }

  /** Drives vsyncs until the loop stops asking for frames; returns how many were drawn. */
  function drain(max = 400): number {
    let t = 0;
    let n = 0;
    while (clock.pending > 0 && n < max) {
      t += 1000 / 60;
      clock.flush(t);
      n++;
    }
    return n;
  }

  it('switched on but off stage (waiting for his first appearance): no rAF at all', async () => {
    await mount();
    expect(clock.requests).toBe(0);
  });

  it('tab hidden: no rAF even when he should appear', async () => {
    const vis = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden');
    await mount();
    await vi.advanceTimersByTimeAsync(5000);
    expect(clock.requests).toBe(0);
    vis.mockRestore();
  });

  it('switched off: the circle animates its pop, then the loop stops (no pending rAF, nothing on pointer rest)', async () => {
    localStorage.setItem(ENABLED_KEY, '0');
    await mount();
    expect(clock.requests).toBeGreaterThan(0);
    const frames = drain();
    expect(frames).toBeLessThan(400);
    expect(clock.pending).toBe(0);
    const before = clock.requests;
    await vi.advanceTimersByTimeAsync(1000);
    // Only the blink timer may schedule a short burst; no continuous loop.
    expect(clock.requests - before).toBeLessThanOrEqual(1);
  });
});

describe('AssistantMascot: a lost hold never gets stuck', () => {
  interface Internals {
    engine: MascotEngine;
    brain: { chatOpened(): void; state: string };
    renderer: { hit: SVGPathElement };
  }

  beforeEach(() => {
    localStorage.clear();
    vi.useFakeTimers();
  });
  afterEach(() => vi.useRealTimers());

  async function mountHeld(): Promise<{ internals: Internals; stage: HTMLElement }> {
    TestBed.configureTestingModule({
      imports: [AssistantMascot, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [
        provideRouter([]),
        { provide: MASCOT_FRAME_CLOCK, useValue: new FakeClock() },
        { provide: AuthService, useValue: { user: signal({ id: 1 }) } },
        { provide: AssistantService, useValue: { status: () => of({ available: true, reason: null, mcp_url: '' }) } },
      ],
    });
    const fixture = TestBed.createComponent(AssistantMascot);
    fixture.detectChanges();
    await vi.advanceTimersByTimeAsync(10);
    const internals = fixture.componentInstance as unknown as Internals;
    // Put him on screen in a draggable state.
    internals.brain.chatOpened();
    const stage = (fixture.nativeElement as HTMLElement).querySelector('.mascot-stage') as HTMLElement;
    const pointer = (type: string, x: number, y: number, target: EventTarget): void => {
      const ev = new MouseEvent(type, { clientX: x, clientY: y, bubbles: true, cancelable: true });
      target.dispatchEvent(ev);
    };
    const r = internals.engine.root;
    pointer('pointerdown', r.x, r.y - 20, internals.renderer.hit);
    pointer('pointermove', r.x + 40, r.y - 60, window);
    pointer('pointermove', r.x + 80, r.y - 100, window);
    return { internals, stage };
  }

  it('lostpointercapture drops him like a cancel (no throw velocity)', async () => {
    const { internals, stage } = await mountHeld();
    expect(internals.engine.held).toBe(true);
    expect(internals.brain.state).toBe('dragged');
    stage.dispatchEvent(new Event('lostpointercapture'));
    expect(internals.engine.held).toBe(false);
    expect(internals.engine.airborne).toBe(true);
    expect(internals.engine.ragdollVelocity(0)).toEqual({ x: 0, y: 0 });
    expect(internals.brain.state).not.toBe('dragged');
  });

  it('window blur ends the hold too', async () => {
    const { internals } = await mountHeld();
    window.dispatchEvent(new Event('blur'));
    expect(internals.engine.held).toBe(false);
  });
});

describe('AssistantMascot: jokes with the chat open', () => {
  beforeEach(() => {
    localStorage.clear();
    vi.useFakeTimers();
  });
  afterEach(() => vi.useRealTimers());

  it('chat open: throw from the seat → get up → the joke is said (component, real wiring)', async () => {
    const clock = new FakeClock();
    TestBed.configureTestingModule({
      imports: [AssistantMascot, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [
        provideRouter([]),
        { provide: MASCOT_FRAME_CLOCK, useValue: clock },
        { provide: AuthService, useValue: { user: signal({ id: 1 }) } },
        { provide: AssistantService, useValue: { status: () => of({ available: true, reason: null, mcp_url: '' }), quips: () => of({ jokes: ['AI joke'], source: 'ai' }) } },
      ],
    });
    const fixture = TestBed.createComponent(AssistantMascot);
    fixture.detectChanges();
    await vi.advanceTimersByTimeAsync(10);
    TestBed.inject(AssistantSettings).openChat();
    fixture.detectChanges();
    await vi.advanceTimersByTimeAsync(3000);
    fixture.detectChanges();
    const cmp = fixture.componentInstance as unknown as { engine: MascotEngine; brain: { state: string; dragStart(): void; dragEnd(): void } };
    const says: (string | null)[] = [];
    const orig = cmp.engine.say.bind(cmp.engine);
    cmp.engine.say = (t: string | null) => {
      says.push(t);
      orig(t);
    };
    vi.spyOn(Math, 'random').mockReturnValue(0.1);
    let t = 0;
    const frames = async (n: number): Promise<void> => {
      for (let i = 0; i < n; i++) {
        t += 1000 / 60;
        await vi.advanceTimersByTimeAsync(1000 / 60);
        clock.flush(t);
        if (clock.pending === 0) {
          (cmp.engine as unknown as { tick(dt: number): unknown }).tick(0);
        }
      }
    };
    await frames(60);
    const states: string[] = [cmp.brain.state];
    const r = cmp.engine.root;
    cmp.brain.dragStart();
    cmp.engine.dragStart(r.x, r.y - 20);
    for (let i = 1; i <= 10; i++) {
      cmp.engine.dragMove(r.x - i * 25, r.y - 20 - i * 10);
      await frames(1);
    }
    cmp.engine.dragEnd();
    cmp.brain.dragEnd();
    for (let i = 0; i < 900; i++) {
      await frames(1);
      if (states.at(-1) !== cmp.brain.state) {
        states.push(cmp.brain.state);
      }
    }
    vi.restoreAllMocks();
    expect({ states, says }).toEqual({ states: expect.arrayContaining(['recovering', 'docked']), says: expect.arrayContaining(['AI joke']) });
  });

});

describe('per-frame allocation guard (new moves and activities)', () => {
  const STAGE2: Stage = { width: 1200, height: 800, ground: 798, seat: { x: 1000, y: 300 }, corner: { x: 1166, y: 766 } };
  it.each([['move-handwalk'], ['act-dance'], ['move-kneeslide'], ['move-unicycle'], ['act-bubbles'], ['act-kite'], ['act-rope'], ['act-yoyo']])(
    '%s: about one full kinematics solve per frame over 600 frames',
    (action) => {
      const e = new MascotEngine(STAGE2, () => 0.4);
      e.play('static', { targetX: 300, blend: 0 });
      e.tick(0.3);
      e.play(action as never, { targetX: 850 });
      e.tick(1 / 60);
      const before = KIN_STATS.fk;
      for (let i = 0; i < 600; i++) {
        if (e.tick(1 / 60).events.some((ev) => ev.type === 'clipDone')) {
          e.play(action as never, { targetX: 850 });
        }
      }
      // The engine solves the skeleton once per step; clips place hands/feet analytically (no pose copies + FK).
      // The small rest is one-off work when a clip or a phase seam starts (blend length from the travel).
      expect((KIN_STATS.fk - before) / 600).toBeLessThanOrEqual(1.5);
    },
  );
});

/**
 * Realistic loop benchmark (fixed 16.7 ms cadence with idle time between frames), opt-in: MASCOT_BENCH=1.
 * Prints p50/p95/p99/max frame cost of engine.tick for every move and activity.
 */
const benchEnv = (globalThis as { process?: { env?: Record<string, string | undefined> } }).process?.env ?? {};
const benchOn = benchEnv['MASCOT_BENCH'] === '1';
describe.skipIf(!benchOn)('realistic loop benchmark (MASCOT_BENCH=1)', () => {
  it('p50/p95/p99/max per move and activity', async () => {
    const { MOVE_ACTIONS } = await import('./moves');
    const { ACTIVITY_ACTIONS } = await import('./activities');
    const STAGE3: Stage = { width: 1200, height: 800, ground: 798, seat: { x: 1000, y: 300 }, corner: { x: 1166, y: 766 } };
    const seconds = Number(benchEnv['MASCOT_BENCH_SECONDS'] ?? '3');
    const pct = (xs: number[], p: number): number => xs[Math.min(xs.length - 1, Math.floor(p * xs.length))];
    for (const action of [...MOVE_ACTIONS, ...ACTIVITY_ACTIONS]) {
      const e = new MascotEngine(STAGE3, Math.random);
      const start = (): void => {
        e.play(action === 'act-fishing' ? 'docked' : 'static', { targetX: 300, blend: 0 });
        e.tick(0.3);
        e.play(action, { targetX: 850 });
      };
      start();
      const times: number[] = [];
      const end = performance.now() + seconds * 1000;
      let next = performance.now();
      while (performance.now() < end) {
        next += 1000 / 60;
        const t0 = performance.now();
        if (e.tick(1 / 60).events.some((ev) => ev.type === 'clipDone')) {
          start();
        }
        times.push(performance.now() - t0);
        await new Promise((r) => setTimeout(r, Math.max(0, next - performance.now())));
      }
      times.sort((a, b) => a - b);
      console.log(`${action} p50=${pct(times, 0.5).toFixed(3)} p95=${pct(times, 0.95).toFixed(3)} p99=${pct(times, 0.99).toFixed(3)} max=${times[times.length - 1].toFixed(2)} ms`);
    }
  }, 600_000);
});
