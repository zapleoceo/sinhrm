import { Component, input, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { AuthService } from '../../core/auth/auth.service';
import { ChannelIcon } from '../../core/ui/channel-icon';
import { MoodCheckinWidget } from '../pulse/mood/mood-checkin.widget';
import { TasksWidget } from '../scripts/tasks/tasks-widget';
import { DashboardPage } from './dashboard.page';
import { Dashboard, statTiles } from './overview.model';
import { OverviewService } from './overview.service';
import { OverviewStore } from './overview.store';

const DASHBOARD: Dashboard = {
  counts: { active: 5, stale: 2, unmatched_inbox: 0, new_today: 1 },
  stale_days: 3,
  my_tasks: { total: 1, overdue: 1, items: [] },
  stale: [],
  funnel: [
    { stage_name: 'New', stage_kind: 'attract', position: 1, count: 4 },
    { stage_name: 'Interview', stage_kind: 'select', position: 2, count: 1 },
  ],
  touches: { days: 7, by_channel: [{ channel: 'call', count: 3 }, { channel: 'telegram', count: 6 }] },
};

describe('Overview', () => {
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), OverviewStore] });
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('service unwraps GET /api/dashboard', () => {
    let d: Dashboard | undefined;
    TestBed.inject(OverviewService).dashboard().subscribe((r) => (d = r));
    http.expectOne({ method: 'GET', url: '/api/dashboard' }).flush({ data: DASHBOARD });
    expect(d?.counts.active).toBe(5);
  });

  it('store derives tiles and bars; flags a failure', () => {
    const store = TestBed.inject(OverviewStore);
    store.load();
    http.expectOne('/api/dashboard').flush({ data: DASHBOARD });
    expect(store.tiles().map((t) => t.tone)).toEqual(['neutral', 'warning', 'neutral', 'neutral']);
    expect(store.funnel().map((r) => r.width)).toEqual([100, 25]);
    expect(store.touches().map((r) => r.width)).toEqual([50, 100]);
    expect(store.touchesTotal()).toBe(9);

    store.load();
    http.expectOne('/api/dashboard').flush(null, { status: 500, statusText: 'x' });
    expect(store.failed()).toBe(true);
    expect(store.loading()).toBe(false);
  });

  it('tiles link to the right pages', () => {
    expect(statTiles(DASHBOARD).map((t) => t.link)).toEqual(['/candidates', '/candidates', '/inbox', '/vacancies']);
  });
});

@Component({ selector: 'app-mood-checkin', template: '' })
class MoodStub {}
@Component({ selector: 'app-tasks-widget', template: '' })
class TasksStub {
  readonly query = input<unknown>();
}
@Component({ selector: 'app-channel-icon', template: '' })
class ChannelStub {
  readonly key = input<unknown>();
}

describe('DashboardPage (route look)', () => {
  it('funnel is a metro line: a station per stage in the colour of its type, sized by share; tiles keyed for the sleeper', async () => {
    TestBed.configureTestingModule({
      imports: [DashboardPage, TranslocoTestingModule.forRoot({ langs: { uk: {} }, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([]), { provide: AuthService, useValue: { user: signal({ name: 'Olena K' }) } }],
    });
    TestBed.overrideComponent(DashboardPage, {
      remove: { imports: [MoodCheckinWidget, TasksWidget, ChannelIcon] },
      add: { imports: [MoodStub, TasksStub, ChannelStub] },
    });
    const fixture = TestBed.createComponent(DashboardPage);
    fixture.detectChanges();
    TestBed.inject(HttpTestingController).expectOne('/api/dashboard').flush({ data: DASHBOARD });
    fixture.detectChanges();
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;

    const stations = Array.from(el.querySelectorAll<HTMLElement>('.metro th .app-station'));
    expect(stations.map((s) => s.dataset['kind'])).toEqual(['attract', 'select']);
    expect(stations.map((s) => s.style.getPropertyValue('--share'))).toEqual(['1', '0.25']);
    expect(stations.every((s) => s.getAttribute('aria-hidden') === 'true')).toBe(true);
    // Row headers keep only the stage name (the station is decorative).
    expect(Array.from(el.querySelectorAll('.metro th')).map((th) => th.textContent?.trim())).toEqual(['New', 'Interview']);
    expect(Array.from(el.querySelectorAll<HTMLElement>('a.tile')).map((t) => t.dataset['key'])).toEqual(['active', 'stale', 'unmatched_inbox', 'new_today']);
  });
});
