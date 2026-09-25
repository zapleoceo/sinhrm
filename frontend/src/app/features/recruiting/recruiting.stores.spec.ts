import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable, Subject, of, throwError } from 'rxjs';
import { BoardStore } from './board/board.store';
import { CandidateCardStore } from './card/candidate-card.store';
import { CandidatesStore } from './candidates/candidates.store';
import { InboxStore } from './inbox/inbox.store';
import { ReportsStore, barWidth, pivotTouches } from './reports/reports.store';
import { Application, Board, Candidate, Paged, Stage, TimelineFilter, TimelineItem, Touchpoint, TouchesReport } from './recruiting.model';
import { RecruitingService } from './recruiting.service';
import { VacanciesStore } from './vacancies/vacancies.store';

const page = <T>(data: T[]): Paged<T> => ({ data, meta: { current_page: 1, per_page: 50, total: data.length, last_page: 1 } });
const stage = (id: number, extra: Partial<Stage> = {}): Stage => ({
  id,
  name: `S${id}`,
  kind: 'select',
  position: id,
  is_terminal: false,
  is_reject: false,
  is_hire: false,
  ...extra,
});
const application = (id: number, stageId: number): Application =>
  ({ id, stage_id: stageId, status: 'active', is_stale: true, candidate: { id: 1, full_name: 'A' } }) as Application;
const candidate = (id: number): Candidate => ({ id, full_name: `C${id}`, applications: [] }) as unknown as Candidate;
const touch = (id: number): Touchpoint => ({ id, channel: 'telegram', meta: {} }) as Touchpoint;

class FakeApi {
  move$ = new Subject<Application>();
  board$: Observable<Board> = of({
    vacancy: { id: 1, stages: [stage(1), stage(2), stage(3, { is_reject: true, is_terminal: true, kind: 'closed' })] },
    applications: [application(10, 1), application(11, 2)],
  } as unknown as Board);
  candidates$: Observable<Paged<Candidate>> = of(page([candidate(1), candidate(2), candidate(3)]));
  timelineCalls: { filters: readonly TimelineFilter[]; page: number }[] = [];
  board = () => this.board$;
  rejectReasons = () => of([{ id: 1, name: 'R', active: true }]);
  move = () => this.move$;
  vacancies = () => of(page([]));
  candidates = () => this.candidates$;
  candidate = (id: number) => of(candidate(id));
  timeline = (_id: number, filters: readonly TimelineFilter[], p: number) => {
    this.timelineCalls.push({ filters: [...filters], page: p });
    const items = [{ type: 'touchpoint', at: '2026-09-01', touchpoint: touch(p) } as TimelineItem];
    return of({ data: items, meta: { current_page: p, per_page: 30, total: 2, last_page: 2 } });
  };
  logTouch = () => of(touch(99));
  inbox = () => of(page([touch(1), touch(2)]));
  linkInbox = () => of(touch(1));
  createFromInbox = () => of(candidate(5));
  touchesReport = () => of({ range: { from: 'a', to: 'b' }, rows: [], totals: { total: 0, via_product: 0, captured: 0 } });
  funnelReport = () =>
    of({
      range: { from: 'a', to: 'b' },
      rows: [
        { vacancy_id: 1, vacancy_title: 'V1', stage_id: 1, stage_name: 'S1', stage_kind: 'attract', position: 1, count: 2 },
        { vacancy_id: 1, vacancy_title: 'V1', stage_id: 2, stage_name: 'S2', stage_kind: 'select', position: 2, count: 1 },
        { vacancy_id: 2, vacancy_title: 'V2', stage_id: 1, stage_name: 'S1', stage_kind: 'attract', position: 1, count: 4 },
      ],
      totals: { total: 7 },
    });
  sourcesReport = () => of({ range: { from: 'a', to: 'b' }, rows: [], totals: { candidates: 0, hired: 0 } });
  rejectReasonsReport = () => of({ range: { from: 'a', to: 'b' }, rows: [], totals: { total: 0 } });
}

function setup<T>(store: new () => T): { store: T; api: FakeApi } {
  const api = new FakeApi();
  TestBed.configureTestingModule({ providers: [store, { provide: RecruitingService, useValue: api }] });
  return { store: TestBed.inject(store), api };
}

describe('BoardStore', () => {
  it('groups columns, counts stale cards and moves optimistically', () => {
    const { store, api } = setup(BoardStore);
    store.load(1);
    expect(store.columns().map((c) => c.items.length)).toEqual([1, 1, 0]);
    expect(store.staleCount()).toBe(2);

    store.move(store.board()!.applications[0], stage(2), {}, () => undefined);
    expect(store.columns()[1].items.length).toBe(2);
    expect(store.pending().has(10)).toBe(true);
    api.move$.next({ ...application(10, 2), is_stale: false });
    expect(store.pending().size).toBe(0);
    expect(store.board()!.applications[0].candidate?.full_name).toBe('A');
  });

  it('rolls back a refused move and reports the error key', () => {
    const { store, api } = setup(BoardStore);
    store.load(1);
    let key = '';
    store.move(store.board()!.applications[0], stage(3, { is_reject: true, is_terminal: true, kind: 'closed' }), {}, (k) => (key = k));
    expect(store.board()!.applications[0].status).toBe('rejected');
    api.move$.error(new HttpErrorResponse({ status: 422, error: { code: 'reject_reason_required' } }));
    expect(store.board()!.applications[0].stage_id).toBe(1);
    expect(key).toBe('recruiting.errors.reject_reason_required');
    expect(store.needsReason(stage(3, { is_reject: true }))).toBe(true);
  });

  it('flags a failed load', () => {
    const { store, api } = setup(BoardStore);
    api.board$ = throwError(() => new Error('down'));
    store.load(1);
    expect(store.failed()).toBe(true);
  });
});

describe('CandidatesStore', () => {
  it('navigates neighbours with edges', () => {
    const { store } = setup(CandidatesStore);
    store.load();
    expect(store.neighbour(1)).toBe(1); // nothing selected → first
    store.selectedId.set(2);
    expect(store.neighbour(1)).toBe(3);
    expect(store.neighbour(-1)).toBe(1);
    store.selectedId.set(3);
    expect(store.neighbour(1)).toBeNull();
  });

  it('filters reset the page', () => {
    const { store } = setup(CandidatesStore);
    store.setPage(3, 50);
    store.patchQuery({ status: 'active' });
    expect(store.query()).toEqual({ page: 1, perPage: 50, status: 'active' });
  });
});

describe('CandidateCardStore', () => {
  it('opens a candidate, toggles filters and pages the timeline', () => {
    const { store, api } = setup(CandidateCardStore);
    store.open(7);
    expect(store.candidate()?.id).toBe(7);
    expect(store.timeline().length).toBe(1);

    store.loadMore();
    expect(store.timeline().length).toBe(2);
    expect(api.timelineCalls.at(-1)).toEqual({ filters: [], page: 2 });

    store.toggleFilter('call');
    store.toggleFilter('stage');
    expect(api.timelineCalls.at(-1)).toEqual({ filters: ['call', 'stage'], page: 1 });
    store.toggleFilter('call');
    expect(store.filters()).toEqual(['stage']);
    store.clearFilters();
    expect(store.filters()).toEqual([]);
  });

  it('refreshes after logging a touch', () => {
    const { store, api } = setup(CandidateCardStore);
    store.open(7);
    const before = api.timelineCalls.length;
    store.logTouch({ channel: 'note', body: 'x' }).subscribe();
    expect(api.timelineCalls.length).toBe(before + 1);
  });
});

describe('InboxStore', () => {
  it('removes resolved messages', () => {
    const { store } = setup(InboxStore);
    store.load();
    expect(store.total()).toBe(2);
    store.link(touch(1), 5).subscribe();
    expect(store.items().map((m) => m.id)).toEqual([2]);
    store.createCandidate(touch(2), { full_name: 'New' }).subscribe();
    expect(store.items()).toEqual([]);
    expect(store.total()).toBe(0);
  });
});

describe('VacanciesStore', () => {
  it('loads with the open filter by default', () => {
    const { store } = setup(VacanciesStore);
    store.load();
    expect(store.query().status).toBe('open');
    expect(store.loading()).toBe(false);
  });
});

describe('ReportsStore', () => {
  it('loads all reports and groups the funnel by vacancy', () => {
    const { store } = setup(ReportsStore);
    store.load();
    expect(store.funnelByVacancy().map((g) => [g.title, g.rows.length])).toEqual([
      ['V1', 2],
      ['V2', 1],
    ]);
    store.setRange({ from: '2026-09-01', to: '2026-09-02' });
    expect(store.range().from).toBe('2026-09-01');
  });

  it('pivots touches by recruiter and splits via product / captured', () => {
    const report: TouchesReport = {
      range: { from: 'a', to: 'b' },
      totals: { total: 6, via_product: 2, captured: 4 },
      rows: [
        { author_id: 1, author_name: 'Ann', channel: 'call', via_product: true, count: 2 },
        { author_id: 1, author_name: 'Ann', channel: 'call', via_product: false, count: 1 },
        { author_id: 1, author_name: 'Ann', channel: 'telegram', via_product: false, count: 2 },
        { author_id: null, author_name: null, channel: 'viber', via_product: false, count: 1 },
      ],
    };
    const rows = pivotTouches(report);
    expect(rows[0]).toEqual({ name: 'Ann', total: 5, viaProduct: 2, captured: 3, byChannel: { call: 3, telegram: 2 } });
    expect(rows[1].name).toBe('—');
    expect(pivotTouches(null)).toEqual([]);
    expect(barWidth(5, 10)).toBe(50);
    expect(barWidth(1, 0)).toBe(0);
  });
});
