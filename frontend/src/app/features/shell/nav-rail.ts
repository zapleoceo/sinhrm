/** Desktop sidebar that collapses to an icon-only rail: remembered state, and the tooltip each rail item shows. */
import { BreakpointObserver } from '@angular/cdk/layout';
import { Directive, Injectable, computed, effect, inject, input, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { MatTooltip } from '@angular/material/tooltip';
import { map } from 'rxjs';

const KEY = 'sinhrm.nav.collapsed';

/** Below this width the sidebar is an off-canvas drawer, which never collapses to a rail. */
export const NARROW_QUERY = '(max-width: 767.98px)';

/** Reads the remembered rail state; any storage problem → expanded. */
export function loadCollapsed(): boolean {
  try {
    return localStorage.getItem(KEY) === '1';
  } catch {
    return false;
  }
}

/** Saves the rail state; silently ignores unavailable storage (the state then lives only in memory). */
export function saveCollapsed(collapsed: boolean): void {
  try {
    localStorage.setItem(KEY, collapsed ? '1' : '0');
  } catch {
    /* storage unavailable */
  }
}

/** Layout state of the shell sidebar, provided by the shell layout (one per shell). */
@Injectable()
export class NavRail {
  /** Narrow screens: the sidebar is a drawer behind a top bar. */
  readonly narrow = toSignal(inject(BreakpointObserver).observe(NARROW_QUERY).pipe(map((s) => s.matches)), { initialValue: false });
  /** What the user chose (kept while the screen is narrow, applied again on a wide screen). */
  private readonly chosen = signal(loadCollapsed());
  /** The sidebar is shown as an icon-only rail right now. */
  readonly collapsed = computed(() => this.chosen() && !this.narrow());

  toggle(): void {
    this.set(!this.chosen());
  }

  expand(): void {
    this.set(false);
  }

  private set(collapsed: boolean): void {
    this.chosen.set(collapsed);
    saveCollapsed(collapsed);
  }
}

/**
 * Tooltip with the item name, shown only while the sidebar is a rail (the label is visually hidden there;
 * it stays in the accessible name). Off in the expanded sidebar, so nothing is announced twice there.
 */
@Directive({
  selector: '[appRailTip]',
  hostDirectives: [MatTooltip],
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
