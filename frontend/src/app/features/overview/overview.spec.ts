import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
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
