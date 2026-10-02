import { Component, input, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { Translation, TranslocoTestingModule } from '@jsverse/transloco';
import { AuthService } from '../../core/auth/auth.service';
import { ChannelIcon } from '../../core/ui/channel-icon';
import { MoodCheckinWidget } from '../pulse/mood/mood-checkin.widget';
import { TasksWidget } from '../scripts/tasks/tasks-widget';
import { DashboardPage } from './dashboard.page';
import { Dashboard, DayRouteItem, routeScale, routeStops, statTiles, touchSegments, wallClock } from './overview.model';
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

// Local times without an offset: the line works in the browser's time zone.
const INTERVIEW: DayRouteItem = { kind: 'interview', id: 7, at: '2026-10-02T11:00:00', end: '2026-10-02T12:00:00', title: 'Tech interview', meeting_type: 'online', candidate: { id: 3, name: 'Test Candidate' } };
const TASK: DayRouteItem = { kind: 'task', id: 9, at: '2026-10-02T15:00:00', end: null, title: 'Send offer', type: 'manual', candidate: null };
const FULL: Dashboard = {
  ...DASHBOARD,
  funnel_insights: {
    period_days: 90,
    min_sample: 10,
    min_offer_observations: 3,
    bottleneck: { from: 'Offer', to: 'Hired', from_kind: 'hire', to_kind: 'hire', conversion: 30, passed: 3, decided: 10 },
    offer_path: { days: 18, observations: 4 },
  },
  day_route: { date: '2026-10-02', interviews: 1, tasks: 1, items: [INTERVIEW, TASK] },
};

describe('Overview day route and touches (pure)', () => {
  it('scale is 9:00-19:00 by default, widens to whole hours around events, now only for today', () => {
    const now = new Date(2026, 9, 2, 9, 30);
    const s = routeScale([INTERVIEW, TASK], now, '2026-10-02');
    expect([s.from, s.to]).toEqual([9, 19]);
    expect(s.hours.map((h) => h.h)).toEqual([9, 11, 13, 15, 17, 19]);
    expect(s.now).toBe(5);
    expect(s.nowLabel).toBe('09:30');
    expect(routeStops([INTERVIEW, TASK], s).map((x) => [x.left, x.time])).toEqual([[20, '11:00'], [60, '15:00']]);

    const wide = routeScale([{ ...TASK, at: '2026-10-02T07:15:00' }, { ...TASK, at: '2026-10-02T20:40:00' }], now, '2026-10-01');
    expect([wide.from, wide.to]).toEqual([7, 21]);
    expect(wide.now).toBeNull();
  });

  it('day line reads the zone the backend cut the day in: 00:00-03:00 Kyiv is today there, yesterday in UTC', () => {
    // Summer (+03:00): 21:45 UTC on 1 Oct = 00:45 Kyiv on 2 Oct; a meeting at 00:30 Kyiv.
    const night: DayRouteItem = { ...INTERVIEW, at: '2026-10-01T21:30:00+00:00', end: null };
    const now = new Date('2026-10-01T21:45:00Z');
    const kyiv = routeScale([night], now, '2026-10-02', 'Europe/Kyiv');
    expect([kyiv.from, kyiv.to]).toEqual([0, 19]);
    expect(kyiv.nowLabel).toBe('00:45');
    expect(kyiv.now).not.toBeNull();
    expect(routeStops([night], kyiv, 'Europe/Kyiv').map((x) => [x.left, x.time])).toEqual([[2.6, '00:30']]);
    // Read in UTC the same moment is still 1 Oct: no «now» on the 2 Oct line.
    expect(routeScale([night], now, '2026-10-02', 'UTC').now).toBeNull();
    // Winter (+02:00): 23:30 UTC on 15 Jan is 01:30 on 16 Jan in Kyiv.
    expect(wallClock(new Date('2026-01-15T23:30:00Z'), 'Europe/Kyiv')).toEqual({ date: '2026-01-16', h: 1, m: 30 });
    expect(wallClock(new Date('2026-01-16T00:30:00Z'), 'Europe/Kyiv')).toEqual({ date: '2026-01-16', h: 2, m: 30 });
    expect(wallClock(new Date('2026-07-15T23:30:00Z'), 'Europe/Kyiv')).toEqual({ date: '2026-07-16', h: 2, m: 30 });
  });

  it('touch segments: share of the total and a colour slot by order, empty channels dropped', () => {
    expect(touchSegments([{ channel: 'email', count: 18 }, { channel: 'telegram', count: 0 }, { channel: 'call', count: 2 }])).toEqual([
      { channel: 'email', count: 18, share: 90, slot: 0 },
      { channel: 'call', count: 2, share: 10, slot: 1 },
    ]);
    expect(touchSegments([])).toEqual([]);
  });
});

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
    expect(d).toEqual(DASHBOARD); // the { data } wrapper is dropped (core unwrapData), the body is untouched
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

function setup(roles: string[] = ['recruiter'], modules?: string[], uk: Translation = {}) {
  TestBed.configureTestingModule({
    imports: [DashboardPage, TranslocoTestingModule.forRoot({ langs: { uk }, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
    providers: [
      provideHttpClient(),
      provideHttpClientTesting(),
      provideRouter([]),
      { provide: AuthService, useValue: { user: signal({ name: 'Olena K', roles }), hasModule: (m: string) => modules === undefined || modules.includes(m) } },
    ],
  });
  TestBed.overrideComponent(DashboardPage, {
    remove: { imports: [MoodCheckinWidget, TasksWidget, ChannelIcon] },
    add: { imports: [MoodStub, TasksStub, ChannelStub] },
  });
  return TestBed.createComponent(DashboardPage);
}

async function render(data: Dashboard, roles?: string[], modules?: string[], uk?: Translation): Promise<HTMLElement> {
  const fixture = setup(roles, modules, uk);
  fixture.detectChanges();
  TestBed.inject(HttpTestingController).expectOne('/api/dashboard').flush({ data });
  fixture.detectChanges();
  await fixture.whenStable();
  return fixture.nativeElement as HTMLElement;
}

describe('DashboardPage (mock-up layout)', () => {
  it('day route: a station per event on the line and an ordered list with time, kind, candidate link and title', async () => {
    const el = await render(FULL);
    const route = el.querySelector('section.route');
    expect(route?.querySelector('h2')?.textContent?.trim()).toBe('overview.route.title');
    expect(route?.querySelectorAll('.line .stop').length).toBe(2);
    expect(route?.querySelector('.line')?.getAttribute('aria-hidden')).toBe('true');
    const rows = Array.from(route?.querySelectorAll<HTMLElement>('.stops li') ?? []);
    expect(rows.map((r) => r.dataset['kind'])).toEqual(['interview', 'task']);
    expect(rows[0].querySelector('time')?.textContent).toBe('11:00');
    expect(rows[0].querySelector('a')?.getAttribute('href')).toBe('/candidates/3');
    expect(rows[1].textContent).toContain('Send offer');
    // The route comes first, then the counters, then the 7+5 grid.
    const order = Array.from(el.querySelectorAll('section.route, section.tiles, .grid.main')).map((n) =>
      ['route', 'tiles', 'main'].find((c) => n.classList.contains(c)),
    );
    expect(order).toEqual(['route', 'tiles', 'main']);
    expect(Array.from(el.querySelectorAll('.grid.main > section')).map((n) => (n.classList.contains('s7') ? 7 : 5))).toEqual([7, 5, 7, 5]);
  });

  it('day route without events is an honest empty state', async () => {
    const el = await render({ ...FULL, day_route: { date: '2026-10-02', interviews: 0, tasks: 0, items: [] } });
    expect(el.querySelector('section.route .app-empty')).not.toBeNull();
    expect(el.querySelector('section.route .line')).toBeNull();
  });

  it('funnel captions only for data the API returned; a hint when there is too little', async () => {
    let el = await render(FULL);
    expect(el.querySelector('[data-insight="bottleneck"] b')?.textContent).toBe('Offer → Hired');
    expect(el.querySelector('[data-insight="offer"]')).not.toBeNull();
    expect(el.querySelector('[data-insight="none"]')).toBeNull();

    TestBed.resetTestingModule();
    const insights = FULL.funnel_insights;
    if (!insights) throw new Error('fixture');
    el = await render({ ...FULL, funnel_insights: { ...insights, bottleneck: null, offer_path: null } });
    expect(el.querySelector('[data-insight="bottleneck"]')).toBeNull();
    expect(el.querySelector('[data-insight="offer"]')).toBeNull();
    expect(el.querySelector('[data-insight="none"]')).not.toBeNull();
  });

  it('funnel captions: the bottleneck shows «N% (passed of decided)», the hint names both thresholds', async () => {
    const uk: Translation = {
      overview: { insights: { bottleneckRate: '{{p}}% ({{passed}} з {{decided}}) / {{days}}', notEnough: 'вузьке {{n}}, офер {{m}}, {{days}}' } },
    };
    let el = await render(FULL, undefined, undefined, uk);
    expect(el.querySelector('[data-insight="bottleneck"] .muted')?.textContent?.trim()).toBe('30% (3 з 10) / 90');

    TestBed.resetTestingModule();
    const insights = FULL.funnel_insights;
    if (!insights) throw new Error('fixture');
    el = await render({ ...FULL, funnel_insights: { ...insights, bottleneck: null, offer_path: null } }, undefined, undefined, uk);
    expect(el.querySelector('[data-insight="none"]')?.textContent?.trim()).toBe('вузьке 10, офер 3, 90');
  });

  it('header date is read in the zone of the day route, so the crumb and the route show the same day', async () => {
    const el = await render({ ...FULL, day_route: { date: '2026-10-02', timezone: 'Pacific/Kiritimati', interviews: 0, tasks: 0, items: [] } });
    expect(el.querySelector('.crumb time')?.getAttribute('datetime')).toBe(wallClock(new Date(), 'Pacific/Kiritimati').date);
  });

  it('touches: a stacked bar (decorative) and a legend with channel and number', async () => {
    const el = await render(FULL);
    const bar = el.querySelector('.stackbar');
    expect(bar?.getAttribute('aria-hidden')).toBe('true');
    expect(Array.from(bar?.querySelectorAll<HTMLElement>('i') ?? []).map((i) => i.style.flexGrow)).toEqual(['3', '6']);
    expect(Array.from(el.querySelectorAll('.legend li b')).map((b) => b.textContent)).toEqual(['3', '6']);
  });

  it('header: my tasks link always, new candidate only for recruiting writers with the module on', async () => {
    let el = await render(FULL);
    expect(el.querySelector('a.tool')?.getAttribute('href')).toBe('/tasks');
    expect(el.querySelectorAll('.tools button').length).toBe(2); // new candidate + refresh

    const cases: [string[], string[] | undefined][] = [[['viewer'], undefined], [['recruiter'], ['overview']]];
    for (const [roles, modules] of cases) {
      TestBed.resetTestingModule();
      el = await render(FULL, roles, modules);
      expect(el.querySelectorAll('.tools button').length).toBe(1);
    }
  });
});

describe('DashboardPage (route look)', () => {
  it('funnel is a metro line: a station per stage in the colour of its type, sized by share; tiles keyed for the sleeper', async () => {
    TestBed.configureTestingModule({
      imports: [DashboardPage, TranslocoTestingModule.forRoot({ langs: { uk: {} }, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([]), { provide: AuthService, useValue: { user: signal({ name: 'Olena K' }), hasModule: () => true } }],
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
