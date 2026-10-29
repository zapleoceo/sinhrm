import { Injectable, effect, inject, signal } from '@angular/core';
import { AuthService } from '../../core/auth/auth.service';
import {
  DEFAULT_DESKTOP_LAYOUT,
  DesktopLayout,
  DesktopWidgetId,
  DesktopWidgetSize,
  parseDesktopLayout,
} from './desktop.model';

const STORAGE_PREFIX = 'sinhrm.desktop.layout.v1.';
const MAX_WIDGETS = 12;
const EMPTY_LAYOUT: DesktopLayout = { version: 1, widgets: [] };

export function desktopLayoutKey(userId: number): string {
  return `${STORAGE_PREFIX}${userId}`;
}

/** Per-account presentation metadata only. Domain records and API results never enter browser storage. */
@Injectable()
export class DesktopLayoutStore {
  private readonly auth = inject(AuthService);
  private ownerId: number | null = null;
  readonly layout = signal<DesktopLayout>(EMPTY_LAYOUT);

  constructor() {
    effect(() => {
      const userId = this.auth.user()?.id ?? null;
      this.ownerId = userId;
      this.layout.set(userId === null ? EMPTY_LAYOUT : this.read(userId));
    });
  }

  add(id: DesktopWidgetId): boolean {
    const current = this.layout();
    if (this.ownerId === null || current.widgets.some((widget) => widget.id === id) || current.widgets.length >= MAX_WIDGETS) return false;
    this.update([...current.widgets, { id, size: 'm' }]);
    return true;
  }

  remove(id: DesktopWidgetId): void {
    this.update(this.layout().widgets.filter((widget) => widget.id !== id));
  }

  resize(id: DesktopWidgetId, size: DesktopWidgetSize): void {
    this.update(this.layout().widgets.map((widget) => (widget.id === id ? { id, size } : widget)));
  }

  move(from: number, to: number): void {
    const widgets = [...this.layout().widgets];
    if (!Number.isInteger(from) || !Number.isInteger(to) || from < 0 || to < 0 || from >= widgets.length || to >= widgets.length || from === to) return;
    const [widget] = widgets.splice(from, 1);
    widgets.splice(to, 0, widget);
    this.update(widgets);
  }

  moveVisible(visibleIds: readonly DesktopWidgetId[], from: number, to: number): void {
    if (!Number.isInteger(from) || !Number.isInteger(to) || from < 0 || to < 0 || from >= visibleIds.length || to >= visibleIds.length || from === to) return;
    const visible = visibleIds.map((id) => this.layout().widgets.find((widget) => widget.id === id)).filter((widget) => widget !== undefined);
    if (visible.length !== visibleIds.length) return;
    const [widget] = visible.splice(from, 1);
    visible.splice(to, 0, widget);
    let visibleIndex = 0;
    const ordered = this.layout().widgets.map((current) => visibleIds.includes(current.id) ? visible[visibleIndex++] : current);
    this.update(ordered);
  }

  private update(widgets: DesktopLayout['widgets']): void {
    if (this.ownerId === null || widgets.length > MAX_WIDGETS) return;
    const layout: DesktopLayout = { version: 1, widgets };
    this.layout.set(layout);
    try {
      globalThis.localStorage?.setItem(desktopLayoutKey(this.ownerId), JSON.stringify(layout));
    } catch {
      // Quota/privacy mode: layout remains usable for this signed-in session.
    }
  }

  private read(userId: number): DesktopLayout {
    try {
      const raw = globalThis.localStorage?.getItem(desktopLayoutKey(userId));
      if (raw === null || raw === undefined) return cloneDefault();
      return parseDesktopLayout(JSON.parse(raw)) ?? cloneDefault();
    } catch {
      return cloneDefault();
    }
  }
}

function cloneDefault(): DesktopLayout {
  return { version: 1, widgets: DEFAULT_DESKTOP_LAYOUT.widgets.map((widget) => ({ ...widget })) };
}
