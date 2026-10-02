/**
 * Desktop sidebar that collapses to an icon-only rail: remembered state, the «auto-hide» mode (a rail that opens over
 * the content while the pointer or keyboard focus is in it) and the tooltip each rail item shows.
 */
import { AriaDescriber } from '@angular/cdk/a11y';
import { BreakpointObserver } from '@angular/cdk/layout';
import { DestroyRef, Directive, Injectable, computed, effect, inject, input, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { MatTooltip } from '@angular/material/tooltip';
import { map } from 'rxjs';

const KEY = 'sinhrm.nav.collapsed';
const AUTO_HIDE_KEY = 'sinhrm.nav.autohide';

/** Below this width the sidebar is an off-canvas drawer, which never collapses to a rail. */
export const NARROW_QUERY = '(max-width: 767.98px)';
/** Auto-hide needs a real hover: on touch screens the sidebar stays as chosen (pinned). */
export const HOVER_QUERY = '(hover: hover) and (pointer: fine)';
/** The pointer may leave the open sidebar for this long (an accidental overshoot) before it folds back. */
export const AUTO_HIDE_DELAY_MS = 250;

function load(key: string): boolean {
  try {
    return localStorage.getItem(key) === '1';
  } catch {
    return false;
  }
}

function save(key: string, on: boolean): void {
  try {
    localStorage.setItem(key, on ? '1' : '0');
  } catch {
    /* storage unavailable: the state then lives only in memory */
  }
}

/** Reads the remembered rail state; any storage problem → expanded. */
export function loadCollapsed(): boolean {
  return load(KEY);
}

/** Saves the rail state; silently ignores unavailable storage (the state then lives only in memory). */
export function saveCollapsed(collapsed: boolean): void {
  save(KEY, collapsed);
}

/** Reads the remembered auto-hide mode; any storage problem → pinned (the default). */
export function loadAutoHide(): boolean {
  return load(AUTO_HIDE_KEY);
}

export function saveAutoHide(on: boolean): void {
  save(AUTO_HIDE_KEY, on);
}

/** Layout state of the shell sidebar, provided by the shell layout (one per shell). */
@Injectable()
export class NavRail {
  private readonly breakpoints = inject(BreakpointObserver);
  /** Narrow screens: the sidebar is a drawer behind a top bar. */
  readonly narrow = toSignal(this.breakpoints.observe(NARROW_QUERY).pipe(map((s) => s.matches)), { initialValue: false });
  private readonly canHover = toSignal(this.breakpoints.observe(HOVER_QUERY).pipe(map((s) => s.matches)), { initialValue: false });
  /** What the user chose (kept while the screen is narrow, applied again on a wide screen). */
  private readonly chosen = signal(loadCollapsed());
  private readonly autoHideChosen = signal(loadAutoHide());
  /** The auto-hide switch is offered: a wide screen with a real pointer (never on the drawer or a touch screen). */
  readonly autoHideAvailable = computed(() => !this.narrow() && this.canHover());
  /** Auto-hide is on: the layout keeps a rail-wide column, the sidebar opens over the content («peek»). */
  readonly autoHide = computed(() => this.autoHideChosen() && this.autoHideAvailable());
  /** In auto-hide mode: the sidebar is open over the content right now. */
  readonly peek = signal(false);
  /** The sidebar is shown as an icon-only rail right now. */
  readonly collapsed = computed(() => !this.narrow() && (this.autoHide() ? !this.peek() : this.chosen()));

  // What keeps the auto-hide sidebar open: the pointer over it, keyboard focus in it, a menu opened from it.
  // Tracked in both modes, so switching auto-hide on under the pointer keeps the sidebar open.
  private hovered = false;
  private focused = false;
  private held = false;
  private timer: ReturnType<typeof setTimeout> | undefined;

  constructor() {
    inject(DestroyRef).onDestroy(() => this.cancel());
  }

  toggle(): void {
    this.set(!this.chosen());
  }

  /** Show the full sidebar: unfold the rail, or (auto-hide) open it over the content. */
  expand(): void {
    if (this.autoHide()) {
      this.open();
      return;
    }
    this.set(false);
  }

  toggleAutoHide(): void {
    const on = !this.autoHideChosen();
    this.autoHideChosen.set(on);
    saveAutoHide(on);
    this.cancel();
    // Switched on with the pointer (or focus) on the sidebar: it stays open until they leave.
    this.peek.set(on && (this.hovered || this.focused || this.held));
  }

  pointerEnter(): void {
    this.hovered = true;
    this.open();
  }

  pointerLeave(): void {
    this.hovered = false;
    this.closeSoon();
  }

  /** Focus moved inside the sidebar; only keyboard focus (focus-visible) holds it open, a click does not. */
  focusIn(keyboard: boolean): void {
    this.focused = keyboard;
    if (keyboard) {
      this.open();
    }
  }

  /** Focus left the sidebar: it folds at once unless the pointer is still over it or a menu holds it. */
  focusOut(): void {
    this.focused = false;
    if (!this.hovered && !this.held) {
      this.close();
    }
  }

  /** A menu opened from the sidebar (the user menu) keeps it open, wherever the pointer goes meanwhile. */
  hold(on: boolean): void {
    this.held = on;
    if (on) {
      this.cancel();
    } else {
      this.closeSoon();
    }
  }

  /** Esc folds the auto-hide sidebar (until the pointer or focus comes back). */
  escape(): void {
    this.hovered = this.focused = false;
    this.close();
  }

  private open(): void {
    if (!this.autoHide()) return;
    this.cancel();
    this.peek.set(true);
  }

  private close(): void {
    this.cancel();
    this.peek.set(false);
  }

  private closeSoon(): void {
    if (!this.autoHide()) return;
    this.cancel();
    this.timer = setTimeout(() => {
      this.timer = undefined;
      if (!this.hovered && !this.focused && !this.held) this.peek.set(false);
    }, AUTO_HIDE_DELAY_MS);
  }

  private cancel(): void {
    if (this.timer !== undefined) {
      clearTimeout(this.timer);
      this.timer = undefined;
    }
  }

  private set(collapsed: boolean): void {
    this.chosen.set(collapsed);
    saveCollapsed(collapsed);
  }
}

/** The tooltip text is already the element's accessible name: its aria-label, or (no aria-label) its own text. */
export function namedBy(el: Element, message: string): boolean {
  const text = message.trim();
  const label = el.getAttribute('aria-label');
  return label !== null ? label.trim() === text : (el.textContent ?? '').includes(text);
}

/**
 * MatTooltip describes its host with the tooltip text (aria-describedby). On the rail the text of a link or group
 * header is its visually hidden label, i.e. already the accessible name, so a screen reader would say it twice
 * («Задачі, Задачі»). This describer skips such descriptions and keeps the others: the user button is named
 * «Відкрити меню» and described by the user's name. Provided per RailTip host, the app-wide AriaDescriber does the work.
 */
@Injectable()
export class RailTipDescriber extends AriaDescriber {
  private readonly shared = inject(AriaDescriber, { skipSelf: true });

  override describe(host: Element, message: string, role?: string): void;
  override describe(host: Element, message: HTMLElement): void;
  override describe(host: Element, message: string | HTMLElement, role?: string): void {
    if (typeof message !== 'string') {
      this.shared.describe(host, message);
    } else if (!namedBy(host, message)) {
      this.shared.describe(host, message, role);
    }
  }

  override removeDescription(host: Element, message: string, role?: string): void;
  override removeDescription(host: Element, message: HTMLElement): void;
  override removeDescription(host: Element, message: string | HTMLElement, role?: string): void {
    if (typeof message === 'string') {
      this.shared.removeDescription(host, message, role);
    } else {
      this.shared.removeDescription(host, message);
    }
  }
}

/**
 * Tooltip with the item name, shown only while the sidebar is a rail (the label is visually hidden there;
 * it stays in the accessible name). Off in the expanded sidebar; never an aria-description that repeats the name.
 */
@Directive({
  selector: '[appRailTip]',
  hostDirectives: [MatTooltip],
  providers: [{ provide: AriaDescriber, useClass: RailTipDescriber }],
})
export class RailTip {
  readonly appRailTip = input.required<string>();
  private readonly rail = inject(NavRail);
  private readonly tip = inject(MatTooltip);

  constructor() {
    this.tip.position = 'right';
    effect(() => {
      this.tip.message = this.appRailTip();
      this.tip.disabled = !this.rail.collapsed();
    });
  }
}
