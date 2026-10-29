import { CdkDrag, CdkDragDrop, CdkDragHandle, CdkDropList } from '@angular/cdk/drag-drop';
import { DOCUMENT } from '@angular/common';
import { ChangeDetectionStrategy, Component, InjectionToken, computed, inject } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { TranslocoPipe } from '@jsverse/transloco';
import { AuthService } from '../../core/auth/auth.service';
import { Dashboard } from '../overview/overview.model';
import {
  DESKTOP_APPS,
  DESKTOP_GROUPS,
  DESKTOP_WIDGETS,
  DesktopAppEntry,
  DesktopWidget,
  DesktopWidgetId,
  DesktopWidgetSize,
  appAllowed,
  widgetAllowed,
} from './desktop.model';
import { DesktopDataStore } from './desktop-data.store';
import { DesktopLayoutStore } from './desktop-layout.store';

export interface DesktopLauncherAdapter {
  launch(route: string): Promise<boolean> | void;
}

/** A narrow seam for the later route-backed window launcher; default navigation stays on the real Router. */
export const DESKTOP_LAUNCHER = new InjectionToken<DesktopLauncherAdapter>('DESKTOP_LAUNCHER', {
  providedIn: 'root',
  factory: () => {
    const router = inject(Router);
    return { launch: (route: string) => router.navigateByUrl(route) };
  },
});

interface ApprovalRow {
  id: string;
  label: string;
  count: number;
  route: string;
}

@Component({
  selector: 'app-desktop-page',
  standalone: true,
  imports: [CdkDropList, CdkDrag, CdkDragHandle, MatButtonModule, MatIconModule, RouterLink, TranslocoPipe],
  providers: [DesktopLayoutStore, DesktopDataStore],
  templateUrl: './desktop.page.html',
  styleUrl: './desktop.page.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DesktopPage {
  private readonly auth = inject(AuthService);
  private readonly launcher = inject(DESKTOP_LAUNCHER);
  private readonly document = inject(DOCUMENT);
  protected readonly layout = inject(DesktopLayoutStore);
  protected readonly data = inject(DesktopDataStore);
  protected readonly groups = DESKTOP_GROUPS;
  protected readonly widgetDefinitions = DESKTOP_WIDGETS;
  protected readonly widgetSizes: readonly DesktopWidgetSize[] = ['s', 'm', 'l'];
  protected readonly touchDragDelay = { touch: 250, mouse: 0 };
  protected readonly todayLabel = computed(() => {
    const locale = this.auth.user()?.locale ?? 'uk';
    return new Intl.DateTimeFormat(locale, { dateStyle: 'full' }).format(new Date());
  });
  protected readonly apps = computed(() => {
    const user = this.auth.user();
    return DESKTOP_APPS.filter((app) => appAllowed(app, user));
  });
  protected readonly appsByGroup = computed(() => {
    const visible = this.apps();
    return this.groups.map((group) => ({ ...group, apps: visible.filter((app) => app.group === group.id) })).filter((group) => group.apps.length > 0);
  });
  protected readonly widgets = computed(() => this.layout.layout().widgets.filter((widget) => this.widgetIsAllowed(widget.id)));
  protected readonly addableWidgets = computed(() => this.widgetDefinitions.filter((widget) =>
    this.widgetIsAllowed(widget.id) && !this.layout.layout().widgets.some((placed) => placed.id === widget.id)));
  protected readonly approvalRows = computed(() => this.buildApprovalRows(this.data.data()));

  protected launch(app: DesktopAppEntry): void {
    void this.launcher.launch(app.route);
  }

  protected addWidget(id: DesktopWidgetId): void {
    if (this.layout.add(id)) queueMicrotask(() => this.document.getElementById(`desktop-widget-remove-${id}`)?.focus());
  }

  protected removeWidget(id: DesktopWidgetId): void {
    this.layout.remove(id);
    queueMicrotask(() => this.document.getElementById(`desktop-widget-add-${id}`)?.focus());
  }

  protected resizeWidget(id: DesktopWidgetId, size: DesktopWidgetSize): void {
    this.layout.resize(id, size);
  }

  protected reorderWidgets(event: CdkDragDrop<DesktopWidget[]>): void {
    const ids = this.widgets().map((widget) => widget.id);
    this.layout.moveVisible(ids, event.previousIndex, event.currentIndex);
  }

  protected moveWidget(id: DesktopWidgetId, delta: -1 | 1): void {
    const ids = this.widgets().map((widget) => widget.id);
    const index = ids.indexOf(id);
    const target = index + delta;
    if (target < 0 || target >= ids.length) return;
    this.layout.moveVisible(ids, index, target);
    queueMicrotask(() => this.document.getElementById(`desktop-widget-move-${id}-${delta < 0 ? 'up' : 'down'}`)?.focus());
  }

  protected refresh(): void {
    this.data.reload();
  }

  protected widgetTitle(id: DesktopWidgetId): string {
    return this.widgetDefinitions.find((widget) => widget.id === id)?.title ?? 'desktop.widgets.title';
  }

  protected widgetIcon(id: DesktopWidgetId): string {
    return this.widgetDefinitions.find((widget) => widget.id === id)?.icon ?? 'widgets';
  }

  protected displayCount(value: number | undefined): string {
    return value === undefined ? '' : new Intl.NumberFormat(this.auth.user()?.locale ?? 'uk').format(value);
  }

  private widgetIsAllowed(id: DesktopWidgetId): boolean {
    return widgetAllowed(id, this.auth.user());
  }

  private buildApprovalRows(dashboard: Dashboard | null): ApprovalRow[] {
    if (!dashboard) return [];
    const rows: ApprovalRow[] = [];
    const user = this.auth.user();
    if (user?.modules === undefined || user.modules.includes('time-off')) {
      const count = dashboard.timeoff?.my_approvals.count;
      if (count !== undefined) rows.push({ id: 'timeoff', label: 'desktop.widgets.approvals.timeoff', count, route: '/timeoff/approvals' });
    }
    if (user?.modules === undefined || user.modules.includes('time')) {
      const count = dashboard.time?.my_approvals.count;
      if (count !== undefined) rows.push({ id: 'time', label: 'desktop.widgets.approvals.time', count, route: '/time/approvals' });
    }
    if (user?.modules === undefined || user.modules.includes('hiring-requests')) {
      const count = dashboard.hiring?.my_approvals.count;
      if (count !== undefined) rows.push({ id: 'hiring', label: 'desktop.widgets.approvals.hiring', count, route: '/hiring-requests/inbox' });
    }
    return rows;
  }
}
