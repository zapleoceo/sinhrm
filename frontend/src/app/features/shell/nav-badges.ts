import { DOCUMENT } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, Injectable, computed, inject, input, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { EMPTY, Observable, Subscription, catchError, distinctUntilChanged, fromEvent, map, merge, startWith, switchMap, timer } from 'rxjs';
import { NavGroupId } from './nav-groups';

/** Sidebar counters from GET /api/nav/badges; a missing key means the user has no such item. */
export type NavBadges = Partial<Record<NavBadgeKey, number>>;
export type NavBadgeKey =
  | 'tasks'
  | 'inbox'
  | 'hiring_inbox'
  | 'timeoff_approvals'
  | 'time_approvals'
  | 'my_documents'
  | 'surveys'
  | 'desk_mine'
  | 'desk_queue'
  | 'safe_speak'
  | 'mail_unknown';

/** How often the counters refresh while the tab is visible. */
export const NAV_BADGES_INTERVAL_MS = 60_000;

/** Which counters a collapsed group header adds up. */
export const NAV_GROUP_BADGES: Record<NavGroupId, readonly NavBadgeKey[]> = {
  recruiting: ['inbox', 'hiring_inbox'],
  people: ['timeoff_approvals', 'time_approvals', 'my_documents'],
  perform: ['surveys'],
  services: ['desk_mine'],
  admin: ['desk_queue', 'safe_speak', 'mail_unknown'],
};

/** Text of a badge: nothing for 0, "99+" above 99. */
export function badgeText(count: number): string {
  if (count <= 0) return '';
  return count > 99 ? '99+' : String(count);
}

export function groupBadgeSum(badges: NavBadges, group: NavGroupId): number {
  return NAV_GROUP_BADGES[group].reduce((sum, key) => sum + (badges[key] ?? 0), 0);
}

/** Loads the counters: on start, every minute while the tab is visible, when it becomes visible, and after navigation. */
@Injectable({ providedIn: 'root' })
export class NavBadgesService {
  private readonly http = inject(HttpClient);
  private readonly doc = inject(DOCUMENT);

  readonly badges = signal<NavBadges>({});

  /** Starts refreshing; unsubscribe to stop. Nothing is requested while the tab is hidden. */
  watch(navigations: Observable<unknown>): Subscription {
    const visible = fromEvent(this.doc, 'visibilitychange').pipe(
      startWith(null),
      map(() => this.doc.visibilityState !== 'hidden'),
      distinctUntilChanged(),
    );
    const ticks = visible.pipe(switchMap((isVisible) => (isVisible ? merge(timer(0, NAV_BADGES_INTERVAL_MS), navigations) : EMPTY)));

    return ticks
      .pipe(switchMap(() => this.http.get<{ data: NavBadges }>('/api/nav/badges').pipe(catchError(() => EMPTY))))
      .subscribe((r) => this.badges.set(r.data ?? {}));
  }
}

/** A small counter pill next to a menu item; hidden at 0, capped at "99+". */
@Component({
  selector: 'app-nav-badge',
  imports: [TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (text(); as t) {
      <span class="nav-badge" role="img" [attr.aria-label]="'shell.badge.new' | transloco: { count: count() }">{{ t }}</span>
    }
  `,
  styles: `
    :host { display: contents; }
    .nav-badge {
      margin-left: auto;
      min-width: 1.25rem;
      padding: 0 0.375rem;
      border-radius: 999px;
      background: var(--mat-sys-primary);
      color: var(--mat-sys-on-primary);
      font: var(--mat-sys-label-small);
      line-height: 1.25rem;
      text-align: center;
      text-transform: none;
      letter-spacing: normal;
      box-sizing: border-box;
    }
  `,
})
export class NavBadge {
  readonly count = input(0);
  protected readonly text = computed(() => badgeText(this.count()));
}
