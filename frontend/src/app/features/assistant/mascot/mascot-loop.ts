import { InjectionToken } from '@angular/core';

/** requestAnimationFrame / cancelAnimationFrame (fake in tests). */
export interface FrameClock {
  request(cb: (time: number) => void): number;
  cancel(handle: number): void;
}

export const MASCOT_FRAME_CLOCK = new InjectionToken<FrameClock>('MASCOT_FRAME_CLOCK', {
  providedIn: 'root',
  factory: (): FrameClock =>
    typeof globalThis.requestAnimationFrame === 'function'
      ? { request: (cb) => globalThis.requestAnimationFrame(cb), cancel: (h) => globalThis.cancelAnimationFrame(h) }
      : {
          request: (cb) => setTimeout(() => cb(performance.now()), 16) as unknown as number,
          cancel: (h) => clearTimeout(h),
        },
});

/** Average rAF interval above this (ms) for a second means the machine struggles → lite mode. */
export const SLOW_FRAME_MS = 20;

/**
 * The single rAF loop of the mascot, with a budget:
 * - runs only while enabled (figure on screen, tab visible, not paused);
 * - renders at the rate the frame asks for (60 fast motion, 30 calm, 10 asleep) and skips the rest;
 * - stops by itself when the frame reports calm (0 fps) — then `poke()` runs it briefly on demand
 *   (pointer moves for the eyes, a blink), with at most one pending frame.
 */
export class MascotLoop {
  private handle: number | null = null;
  private last = -1;
  private acc = 0;
  private fps = 30;
  private pokeUntil = -Infinity;
  private enabled = false;
  private avgInterval = 16.7;
  private slowFrames = 0;

  constructor(
    private readonly clock: FrameClock,
    /** Draws one frame for the elapsed seconds; returns the wanted fps (0 — calm, may stop). */
    private readonly frame: (dt: number) => number,
    /** Called once when frames are persistently slow. */
    private readonly onSlow: () => void = () => undefined,
  ) {}

  get running(): boolean {
    return this.handle !== null;
  }

  setEnabled(on: boolean): void {
    this.enabled = on;
    if (on) {
      this.kick();
    } else {
      this.halt();
    }
  }

  /** Something changed: make sure a frame is coming (keeps running while the figure is busy). */
  kick(): void {
    if (this.enabled && this.handle === null) {
      this.last = -1;
      // The first vsync after a wake-up always draws.
      this.acc = 1 / this.fps;
      this.handle = this.clock.request(this.tick);
    }
  }

  /** Keep drawing for `ms` even when calm (eyes easing toward the cursor, a blink). */
  poke(ms: number, now: number): void {
    this.pokeUntil = Math.max(this.pokeUntil, now + ms);
    this.kick();
  }

  halt(): void {
    if (this.handle !== null) {
      this.clock.cancel(this.handle);
      this.handle = null;
    }
  }

  private readonly tick = (time: number): void => {
    this.handle = null;
    if (!this.enabled) {
      return;
    }
    const interval = this.last < 0 ? 1000 / 60 : time - this.last;
    this.last = time;
    this.watch(interval);
    this.acc += Math.min(0.1, interval / 1000);
    let calm = false;
    if (this.acc + 0.002 >= 1 / this.fps) {
      const wanted = this.frame(this.acc);
      this.acc = 0;
      calm = wanted <= 0;
      this.fps = calm ? 30 : wanted;
    }
    if (!calm || time < this.pokeUntil) {
      this.handle = this.clock.request(this.tick);
    }
  };

  private watch(interval: number): void {
    if (interval > 250) {
      return;
    }
    this.avgInterval += (interval - this.avgInterval) * 0.05;
    if (this.avgInterval > SLOW_FRAME_MS) {
      this.slowFrames++;
      if (this.slowFrames === 60) {
        this.onSlow();
      }
    } else {
      this.slowFrames = 0;
    }
  }
}
