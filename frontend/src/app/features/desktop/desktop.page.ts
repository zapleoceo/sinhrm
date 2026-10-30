import { CdkDrag, CdkDragDrop, CdkDragHandle, CdkDropList } from '@angular/cdk/drag-drop';
import { DOCUMENT } from '@angular/common';
import { ChangeDetectionStrategy, Component, Injector, afterNextRender, computed, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { AuthService } from '../../core/auth/auth.service';
import { DesktopWallpaperStore } from '../../core/theme/desktop-wallpaper.store';
import { Dashboard } from '../overview/overview.model';
import {
  DESKTOP_APPS,
  DESKTOP_GROUPS,
  DESKTOP_WIDGETS,
  DesktopWidget,
  DesktopWidgetId,
  DesktopWidgetSize,
  appAllowed,
  widgetAllowed,
} from './desktop.model';
import { DesktopDataStore } from './desktop-data.store';
import { DesktopLayoutStore } from './desktop-layout.store';
import { DESKTOP_LAUNCHER } from './desktop-launcher';
import { DesktopAppLauncherComponent } from './desktop-app-launcher.component';

interface ApprovalRow {
  id: string;
  label: string;
  count: number;
  route: string;
}

@Component({
  selector: 'app-desktop-page',
  standalone: true,
  imports: [CdkDropList, CdkDrag, CdkDragHandle, MatButtonModule, MatIconModule, RouterLink, TranslocoPipe, DesktopAppLauncherComponent],
  providers: [DesktopLayoutStore, DesktopDataStore],
  templateUrl: './desktop.page.html',
  styleUrl: './desktop.page.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DesktopPage {
  private readonly auth = inject(AuthService);
  private readonly document = inject(DOCUMENT);
  private readonly injector = inject(Injector);
  private readonly launcher = inject(DESKTOP_LAUNCHER);
  private readonly i18n = inject(TranslocoService);
  // Restore the saved CSS wallpaper when the desktop is opened directly or reloaded.
  private readonly wallpaper = inject(DesktopWallpaperStore);
  protected readonly layout = inject(DesktopLayoutStore);
  protected readonly data = inject(DesktopDataStore);
  protected readonly widgetDefinitions = DESKTOP_WIDGETS;
  protected readonly appGroups = computed(() => {
    const allowedApps = DESKTOP_APPS.filter((app) => appAllowed(app, this.auth.user()));
    return DESKTOP_GROUPS
      .map((group) => ({ ...group, apps: allowedApps.filter((app) => app.group === group.id) }))
      .filter((group) => group.apps.length > 0);
  });
  protected readonly widgetSizes: readonly DesktopWidgetSize[] = ['s', 'm', 'l'];
  protected readonly touchDragDelay = { touch: 250, mouse: 0 };
  protected readonly todayLabel = computed(() => {
    const locale = this.auth.user()?.locale ?? 'uk';
    return new Intl.DateTimeFormat(locale, { dateStyle: 'full' }).format(new Date());
  });
  protected readonly widgets = computed(() => this.layout.layout().widgets.filter((widget) => this.widgetIsAllowed(widget.id)));
  protected readonly addableWidgets = computed(() => this.widgetDefinitions.filter((widget) =>
    this.widgetIsAllowed(widget.id) && !this.layout.layout().widgets.some((placed) => placed.id === widget.id)));
  protected readonly approvalRows = computed(() => this.buildApprovalRows(this.data.data()));

  protected addWidget(id: DesktopWidgetId): void {
    if (this.layout.add(id)) this.focusControlAfterRender(`desktop-widget-remove-${id}`);
  }

  protected removeWidget(id: DesktopWidgetId): void {
    this.layout.remove(id);
    this.focusControlAfterRender(`desktop-widget-add-${id}`);
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
    this.focusControlAfterRender(`desktop-widget-move-${id}-${delta < 0 ? 'up' : 'down'}`);
  }

  private focusControlAfterRender(id: string): void {
    afterNextRender(() => this.document.getElementById(id)?.focus(), { injector: this.injector });
  }

  protected refresh(): void {
    this.data.reload();
  }

  protected launchLink(event: MouseEvent, route: string): void {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    void this.launcher.launch(route);
  }

  protected widgetTitle(id: DesktopWidgetId): string {
    const key = this.widgetDefinitions.find((widget) => widget.id === id)?.title ?? 'desktop.widgets.title';
    return this.i18n.translate(key);
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
