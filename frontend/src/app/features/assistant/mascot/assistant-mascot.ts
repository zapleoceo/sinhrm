import { DOCUMENT } from '@angular/common';
import { ChangeDetectionStrategy, Component, DestroyRef, ElementRef, NgZone, afterNextRender, effect, inject, signal, untracked, viewChild } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { NavigationEnd, Router } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { filter } from 'rxjs';
import { AssistantConversation } from '../assistant-conversation';
import { AssistantSettings } from '../assistant-settings';
import { AssistantChat } from '../chat/assistant-chat';
import { AssistantVoice } from '../voice/assistant-voice';
import { Stage } from './animations';
import { BrainCommand, MascotBrain, dispatchEngineEvent } from './brain';
import { EngineEvent, MascotEngine } from './mascot-engine';
import { MASCOT_FRAME_CLOCK, MascotLoop } from './mascot-loop';
import { MascotRenderer } from './mascot-renderer';
import { Vec } from './skeleton';

const REDUCED_QUERY = '(prefers-reduced-motion: reduce)';
const DRAG_THRESHOLD = 6;
const NEAR = 90;
const CORNER = 34;
const OVERLAY_SELECTOR = '.mat-mdc-snack-bar-container, .mat-mdc-dialog-container';
const DIALOG_SELECTOR = '.mat-mdc-dialog-container';
/** How long the eyes keep easing after a pointer move while the figure is still. */
const EYES_POKE_MS = 320;

interface NavigatorHints {
  hardwareConcurrency?: number;
  connection?: { saveData?: boolean };
}

/** Weak machine or data saver → lite animation by default. */
export function prefersLite(nav: NavigatorHints | undefined): boolean {
  return (nav?.hardwareConcurrency ?? 8) <= 4 || nav?.connection?.saveData === true;
}

/** «Стік»: the hand-drawn helper living on the page edges; mounted once in the shell (docs/modules/assistant.md). */
@Component({
  selector: 'app-assistant-mascot',
  imports: [AssistantChat, MatIconModule, MatMenuModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './assistant-mascot.html',
  styleUrl: './assistant-mascot.scss',
})
export class AssistantMascot {
  private readonly zone = inject(NgZone);
  private readonly document = inject(DOCUMENT);
  private readonly router = inject(Router);
  private readonly i18n = inject(TranslocoService);
  private readonly conversation = inject(AssistantConversation);
  private readonly voice = inject(AssistantVoice);
  private readonly clock = inject(MASCOT_FRAME_CLOCK);
  protected readonly settings = inject(AssistantSettings);
  private readonly stageRef = viewChild.required<ElementRef<HTMLDivElement>>('stage');

  /** Figure (or the "off" circle) is on screen. */
  protected readonly onStage = signal(false);
  /** Last quip, for screen readers. */
  protected readonly spoken = signal('');

  private engine: MascotEngine | null = null;
  private renderer: MascotRenderer | null = null;
  private brain: MascotBrain | null = null;
  private loop: MascotLoop | null = null;
  private reduced = false;
  private autoLite = false;
  private paused = false;
  private viewport = { width: 1280, height: 800 };
  private pointer: Vec | null = null;
  private press: { x: number; y: number; id: number; dragging: boolean } | null = null;
  private lastScroll = { top: 0, t: 0 };
  private seat: Vec | null = null;
  private blinkTimer: ReturnType<typeof setTimeout> | null = null;
  private readonly cleanups: (() => void)[] = [];

  constructor() {
    afterNextRender(() => this.init());
    inject(DestroyRef).onDestroy(() => this.teardown());

    effect(() => {
      const on = this.settings.enabled();
      untracked(() => {
        this.brain?.setEnabled(on);
        this.loop?.kick();
      });
    });
    effect(() => {
      this.settings.lite();
      untracked(() => this.applyLite());
    });
    effect(() => {
      const open = this.settings.chatOpen();
      untracked(() => this.onChat(open));
    });
    effect(() => {
      const mood = this.conversation.mood();
      untracked(() => {
        const last = this.conversation.transcript().at(-1);
        const talkMs = last?.role === 'assistant' ? Math.min(8000, 1500 + last.text.length * 35) : undefined;
        this.brain?.mood(mood.mood, talkMs);
        this.loop?.kick();
      });
    });
    // Voice dictation: he cups his ear while recording and thinks while it is transcribed.
    effect(() => {
      const state = this.voice.state();
      untracked(() => {
        this.brain?.mood(state === 'recording' ? 'listen' : state === 'transcribing' ? 'think' : this.conversation.busy() ? 'think' : 'idle');
        this.loop?.kick();
      });
    });
    this.router.events
      .pipe(
        filter((e): e is NavigationEnd => e instanceof NavigationEnd),
        takeUntilDestroyed(),
      )
      .subscribe((e) => this.brain?.routeChanged(e.urlAfterRedirects));
  }

  protected turnOn(): void {
    this.settings.setEnabled(true);
  }

  protected talk(): void {
    this.settings.openChat();
  }

  /* ───────────── lifecycle ───────────── */

  private init(): void {
    const win = this.document.defaultView;
    if (!win) {
      return;
    }
    const media = typeof win.matchMedia === 'function' ? win.matchMedia(REDUCED_QUERY) : null;
    this.reduced = media?.matches ?? false;
    this.autoLite = prefersLite(win.navigator as NavigatorHints);
    this.cacheViewport();
    const stageEl = this.stageRef().nativeElement;
    this.engine = new MascotEngine(this.stage(), Math.random, this.reduced);
    this.renderer = new MascotRenderer(stageEl, this.document);
    this.brain = new MascotBrain((c) => this.command(c), { isTyping: () => this.isTypingOutsideChat(), width: () => this.viewport.width });
    this.loop = new MascotLoop(
      this.clock,
      (dt) => this.frame(dt),
      () => {
        this.autoLite = true;
        this.applyLite();
      },
    );
    this.applyLite();

    this.zone.runOutsideAngular(() => {
      this.listen(win, 'pointermove', (e) => this.onPointerMove(e as PointerEvent), { passive: true });
      this.listen(win, 'keydown', () => this.brain?.userActivity(), { passive: true });
      this.listen(win, 'resize', () => this.onResize(), { passive: true });
      this.listen(this.document, 'scroll', (e) => this.onScroll(e), { passive: true, capture: true });
      this.listen(this.document, 'visibilitychange', () => {
        if (this.document.visibilityState === 'hidden') {
          this.cancelHold();
        }
        this.syncLoop();
      });
      this.listen(win, 'blur', () => this.cancelHold());
      this.listen(stageEl, 'lostpointercapture', () => this.cancelHold());
      this.listen(this.document, 'focusin', () => this.updatePause());
      this.listen(this.document, 'focusout', () => queueMicrotask(() => this.updatePause()));
      this.listen(stageEl, 'pointerdown', (e) => this.onPointerDown(e as PointerEvent));
      this.listen(stageEl, 'pointerup', (e) => this.onPointerUp(e as PointerEvent));
      this.listen(stageEl, 'pointercancel', () => this.cancelHold());
      this.listen(this.renderer!.hit, 'pointerenter', () => this.brain?.hover(true));
      this.listen(this.renderer!.hit, 'pointerleave', () => this.brain?.hover(false));
      if (media) {
        this.listen(media, 'change', () => {
          this.reduced = media.matches;
          this.engine?.setReducedMotion(this.reduced);
          this.brain?.setReducedMotion(this.reduced);
          this.applyLite();
        });
      }
    });
    this.watchOverlays();
    this.brain.start(this.settings.enabled(), this.reduced);
    this.brain.routeChanged(this.router.url);
    this.syncLoop();
  }

  private teardown(): void {
    this.loop?.setEnabled(false);
    this.clearBlink();
    this.brain?.destroy();
    this.renderer?.destroy();
    for (const off of this.cleanups) {
      off();
    }
    this.cleanups.length = 0;
  }

  private listen(target: EventTarget, type: string, handler: (e: Event) => void, options?: AddEventListenerOptions): void {
    target.addEventListener(type, handler, options);
    this.cleanups.push(() => target.removeEventListener(type, handler, options));
  }

  private cacheViewport(): void {
    const win = this.document.defaultView;
    this.viewport = { width: win?.innerWidth ?? 1280, height: win?.innerHeight ?? 800 };
  }

  private onResize(): void {
    this.cacheViewport();
    this.engine?.setStage(this.stage());
    this.loop?.kick();
  }

  private stage(): Stage {
    const { width, height } = this.viewport;
    return { width, height, ground: height - 2, seat: this.seat, corner: { x: width - CORNER, y: height - CORNER } };
  }

  private applyLite(): void {
    const lite = this.settings.lite() || this.autoLite || this.reduced;
    this.engine?.setLite(lite);
    this.brain?.setLite(lite);
  }

  /* ───────────── brain → engine ───────────── */

  private command(c: BrainCommand): void {
    const engine = this.engine;
    if (!engine) {
      return;
    }
    switch (c.type) {
      case 'play':
        engine.play(c.action, { side: c.side, targetX: c.targetX, blend: c.blend, variant: c.variant });
        break;
      case 'gesture': {
        const target = c.name === 'point' || c.name === 'listen' ? this.pointTarget() : null;
        engine.gesture(c.name, target);
        if (target && c.name === 'point') {
          engine.glance(target, 2);
        }
        break;
      }
      case 'say': {
        const text = c.key ? this.i18n.translate(c.key) : null;
        engine.say(text);
        if (text) {
          this.zone.run(() => this.spoken.set(text));
        }
        break;
      }
      case 'visible':
        this.zone.run(() => this.onStage.set(c.value));
        this.syncLoop();
        break;
    }
    this.loop?.kick();
  }

  /** The confirmation card (or the chat panel) he points at. Read once per gesture, not per frame. */
  private pointTarget(): Vec | null {
    const card = this.document.querySelector('.assistant-confirm') ?? this.document.querySelector('.assistant-panel');
    if (!card) {
      return null;
    }
    const r = card.getBoundingClientRect();
    return { x: r.left + r.width / 2, y: r.top + Math.min(r.height / 2, 60) };
  }

  private onChat(open: boolean): void {
    if (!open) {
      this.seat = null;
      this.engine?.setStage(this.stage());
      this.brain?.chatClosed();
      this.updatePause();
      return;
    }
    // Measure the panel once it is rendered, then sit on its top edge.
    const win = this.document.defaultView;
    const measure = (): void => {
      const panel = this.document.querySelector('.assistant-panel');
      if (panel) {
        const r = panel.getBoundingClientRect();
        this.seat = { x: r.left + r.width * 0.64, y: r.top };
        this.engine?.setStage(this.stage());
      }
      this.brain?.chatOpened();
      this.updatePause();
    };
    if (win && typeof win.requestAnimationFrame === 'function') {
      win.requestAnimationFrame(() => measure());
    } else {
      measure();
    }
  }

  /* ───────────── loop ───────────── */

  private syncLoop(): void {
    const on = !!this.brain?.visible && this.document.visibilityState !== 'hidden' && !this.paused;
    this.loop?.setEnabled(on);
    if (on) {
      this.armBlink();
    } else {
      this.clearBlink();
    }
  }

  /** Draws one frame; returns the fps the next moment needs (0 — calm, the loop may stop). */
  private frame(dt: number): number {
    const engine = this.engine;
    const renderer = this.renderer;
    if (!engine || !renderer) {
      return 0;
    }
    engine.pointer(this.pointer);
    engine.setAudioLevel(this.voice.level());
    const { frame, events } = engine.tick(dt);
    for (const e of events) {
      this.engineEvent(e);
    }
    renderer.render(frame, this.viewport, this.reduced || this.settings.lite() || this.autoLite);
    return engine.fps;
  }

  private engineEvent(e: EngineEvent): void {
    const brain = this.brain;
    if (!brain) {
      return;
    }
    dispatchEngineEvent(brain, e);
  }

  /** While the figure is still (the "off" circle, reduced motion) blinks come from one timer, not a loop. */
  private armBlink(): void {
    if (this.blinkTimer !== null) {
      return;
    }
    this.blinkTimer = setTimeout(
      () => {
        this.blinkTimer = null;
        const win = this.document.defaultView;
        if (this.engine?.calm && this.loop && !this.loop.running && win) {
          this.engine.blink();
          this.loop.poke(220, win.performance.now());
        }
        if (this.brain?.visible) {
          this.armBlink();
        }
      },
      2200 + Math.random() * 3800,
    );
  }

  private clearBlink(): void {
    if (this.blinkTimer !== null) {
      clearTimeout(this.blinkTimer);
      this.blinkTimer = null;
    }
  }

  /** Pause while the user types outside the chat or a modal dialog is open. */
  private updatePause(): void {
    const paused = this.isTypingOutsideChat() || !!this.document.querySelector(DIALOG_SELECTOR);
    if (paused !== this.paused) {
      this.paused = paused;
      this.syncLoop();
    }
  }

  /* ───────────── input ───────────── */

  private onPointerMove(e: PointerEvent): void {
    this.pointer = { x: e.clientX, y: e.clientY };
    this.brain?.userActivity();
    const engine = this.engine;
    if (!engine || !this.brain?.visible) {
      return;
    }
    if (this.press) {
      if (!this.press.dragging && Math.hypot(e.clientX - this.press.x, e.clientY - this.press.y) > DRAG_THRESHOLD && this.brain.canDrag) {
        this.press.dragging = true;
        this.brain.dragStart();
        engine.dragStart(this.press.x, this.press.y, e.timeStamp);
      }
      if (this.press.dragging) {
        engine.dragMove(e.clientX, e.clientY, e.timeStamp);
      }
      this.loop?.kick();
      return;
    }
    if (engine.calm) {
      // Still figure: redraw only the eyes for a moment (one pending frame at most).
      this.loop?.poke(EYES_POKE_MS, e.timeStamp);
    }
    const head = engine.head;
    if (Math.hypot(head.x - e.clientX, head.y - e.clientY) < NEAR) {
      this.brain.pointerNear();
      this.loop?.kick();
    }
  }

  private onPointerDown(e: PointerEvent): void {
    if (e.target !== this.renderer?.hit || !this.settings.enabled()) {
      return;
    }
    e.preventDefault();
    this.press = { x: e.clientX, y: e.clientY, id: e.pointerId, dragging: false };
    this.stageRef().nativeElement.setPointerCapture?.(e.pointerId);
  }

  private onPointerUp(e: PointerEvent | null): void {
    const press = this.press;
    this.press = null;
    if (!press) {
      return;
    }
    this.stageRef().nativeElement.releasePointerCapture?.(press.id);
    if (press.dragging) {
      this.engine?.dragEnd();
      this.brain?.dragEnd();
      this.loop?.kick();
    } else if (e) {
      this.zone.run(() => this.settings.openChat());
    }
  }

  /** The hold can never get stuck: losing the pointer drops him (no throw) like a cancelled pointer. */
  private cancelHold(): void {
    const press = this.press;
    this.press = null;
    if (!press?.dragging) {
      return;
    }
    this.engine?.dragCancel();
    this.brain?.dragEnd();
    this.loop?.kick();
  }

  private onScroll(e: Event): void {
    if (!this.brain?.visible) {
      return;
    }
    const target = e.target;
    const el = target instanceof Element ? target : this.document.scrollingElement;
    if (!el) {
      return;
    }
    const now = e.timeStamp;
    const dt = Math.max(1, now - this.lastScroll.t);
    const top = el.scrollTop;
    const speed = ((top - this.lastScroll.top) / dt) * 1000;
    this.lastScroll = { top, t: now };
    if (Math.abs(speed) > 1500 && dt < 200) {
      this.engine?.jolt(speed);
      this.brain.scrollJolt(speed);
      this.loop?.kick();
    }
  }

  /** He glances (with a little "!") at new toasts and dialogs; an open dialog pauses him. */
  private watchOverlays(): void {
    const Observer = this.document.defaultView?.MutationObserver;
    if (!Observer) {
      return;
    }
    const inner = new Observer((records) => {
      for (const r of records) {
        for (const node of Array.from(r.addedNodes)) {
          const hit = node instanceof Element ? (node.matches(OVERLAY_SELECTOR) ? node : node.querySelector(OVERLAY_SELECTOR)) : null;
          if (hit && this.brain?.visible && !this.paused) {
            const rect = hit.getBoundingClientRect();
            this.engine?.notice({ x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 });
            this.loop?.kick();
          }
        }
      }
      this.updatePause();
    });
    const attach = (): boolean => {
      const container = this.document.querySelector('.cdk-overlay-container');
      if (container) {
        inner.observe(container, { childList: true, subtree: true });
      }
      return !!container;
    };
    if (!attach()) {
      const outer = new Observer(() => {
        if (attach()) {
          outer.disconnect();
        }
      });
      outer.observe(this.document.body, { childList: true });
      this.cleanups.push(() => outer.disconnect());
    }
    this.cleanups.push(() => inner.disconnect());
  }

  /** Typing in any field except the chat composer. */
  private isTypingOutsideChat(): boolean {
    const el = this.document.activeElement;
    if (!el || el.closest('.assistant-panel')) {
      return false;
    }
    const tag = el.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (el as HTMLElement).isContentEditable === true;
  }
}
