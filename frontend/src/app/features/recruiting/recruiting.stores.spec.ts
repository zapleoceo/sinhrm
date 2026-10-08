import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable, Subject, of, throwError } from 'rxjs';
import { BoardStore, moveInLayout } from './board/board.store';
import { CandidateCardStore } from './card/candidate-card.store';
import { CandidatesStore } from './candidates/candidates.store';
import { InboxStore } from './inbox/inbox.store';
import { ReportsStore, barWidth, pivotTouches } from './reports/reports.store';
import { Application, Board, Candidate, Paged, PersonalBoard, PersonalColumn, Stage, TimelineFilter, TimelineItem, Touchpoint, TouchesReport } from './recruiting.model';
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
  file$ = new Subject<void>();
  fileCalls: [number, number | null][] = [];
  personalBoard = () => of<PersonalBoard>({ columns: [{ id: 7, title: 'Топ', color: null, position: 0, hidden: false }], cards: [], layout: ['stage:1', 'stage:2', 'stage:3', 'col:7'] });
  layoutCalls: string[][] = [];
  layout$: Observable<string[]> | null = null;
  savePersonalLayout = (_v: number, keys: string[]) => {
    this.layoutCalls.push(keys);
    return this.layout$ ?? of(keys);
  };
  fileCard = (appId: number, columnId: number | null) => {
    this.fileCalls.push([appId, columnId]);
    return this.file$;
  };
  column$: Observable<PersonalColumn> | null = null;
  addPersonalColumn = (_v: number, body: { title?: string }) => this.column$ ?? of<PersonalColumn>({ id: 8, title: body.title ?? '', color: null, position: 1, hidden: false });
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

  it('files a card into an own column without touching its stage, and rolls back on error', () => {
    const { store, api } = setup(BoardStore);
    store.load(1, true);
    const app = store.board()!.applications[0];
    store.file(app, 7, () => undefined);
    expect(api.fileCalls).toEqual([[10, 7]]);
    expect(store.personalColumns()[0].items.map((a) => a.id)).toEqual([10]);
    expect(store.columns()[0].items.length).toBe(0);
    expect(store.board()!.applications[0].stage_id).toBe(1);

    let key = '';
    api.file$.error(new HttpErrorResponse({ status: 422, error: { code: 'board_column_mismatch' } }));
    store.file(app, 7, (k) => (key = k));
    expect(key).toBe('recruiting.errors.board_column_mismatch');
    expect(store.personalColumns()[0].items.length).toBe(0);
    expect(store.columns()[0].items.map((a) => a.id)).toEqual([10]);
  });

  it('adds an own column after the existing ones', () => {
    const { store } = setup(BoardStore);
    store.load(1, true);
    store.addColumn(1, 'Чекаю резюме', () => undefined);
    expect(store.personalColumns().map((c) => c.column.title)).toEqual(['Топ', 'Чекаю резюме']);
  });

  it('moves an own column between funnel stages by drag & drop, never a stage', () => {
    const { store, api } = setup(BoardStore);
    store.load(1, true);
    // CDK reports indexes among lanes: col:7 (index 3) dropped at index 1 — between stage 1 and stage 2.
    store.moveLane(1, 3, 1, () => undefined);
    expect(store.lanes().map((l) => l.key)).toEqual(['stage:1', 'col:7', 'stage:2', 'stage:3']);
    expect(api.layoutCalls).toEqual([['stage:1', 'col:7', 'stage:2', 'stage:3']]);
    // Before the first stage, then the keyboard fallback jumps over a stage.
    store.moveLane(1, 1, 0, () => undefined);
    expect(store.lanes()[0].key).toBe('col:7');
    store.shiftColumn(1, store.personalColumns()[0].column, 1, () => undefined);
    expect(store.lanes().map((l) => l.key)).toEqual(['stage:1', 'col:7', 'stage:2', 'stage:3']);
    // A stage cannot be picked up.
    store.moveLane(1, 0, 3, () => undefined);
    expect(store.lanes()[0].key).toBe('stage:1');
    expect(api.layoutCalls.length).toBe(3);
  });

  it('rolls back a refused layout and keeps hidden columns in their slots', () => {
    const { store, api } = setup(BoardStore);
    store.load(1, true);
    let key = '';
    api.layout$ = throwError(() => new HttpErrorResponse({ status: 422, error: { code: 'board_layout_stage_order' } }));
    store.moveLane(1, 3, 0, (k) => (key = k));
    expect(key).toBe('recruiting.errors.board_layout_stage_order');
    expect(store.lanes().map((l) => l.key)).toEqual(['stage:1', 'stage:2', 'stage:3', 'col:7']);
    expect(moveInLayout(['stage:1', 'col:9', 'stage:2', 'col:7'], ['stage:1', 'stage:2', 'col:7'], 2, 0)).toEqual([
      'col:7',
      'col:9',
      'stage:1',
      'stage:2',
    ]);
  });

  it('creates a column right at the «+» slot', () => {
    const { store, api } = setup(BoardStore);
    store.load(1, true);
    store.addColumn(1, 'Чекаю резюме', () => undefined, 1);
    expect(store.lanes().map((l) => l.key)).toEqual(['stage:1', 'col:8', 'stage:2', 'stage:3', 'col:7']);
    expect(api.layoutCalls.at(-1)).toEqual(['stage:1', 'col:8', 'stage:2', 'stage:3', 'col:7']);
  });

  it('takes a filed card out of its own column only after the stage move succeeded', () => {
    const { store, api } = setup(BoardStore);
    store.load(1, true);
    api.file$ = new Subject<void>();
    store.file(store.board()!.applications[0], 7, () => undefined);
    api.fileCalls = [];
    store.moveToStage(store.board()!.applications[0], stage(2), {}, () => undefined);
    expect(api.fileCalls).toEqual([]);
    expect(store.filed().get(10)).toBe(7);
    api.move$.next({ ...application(10, 2), is_stale: false });
    expect(api.fileCalls).toEqual([[10, null]]);
    expect(store.filed().has(10)).toBe(false);

    // Refused move: the card stays filed.
    store.file(store.board()!.applications[0], 7, () => undefined);
    api.fileCalls = [];
    api.move$ = new Subject<Application>();
    store.moveToStage(store.board()!.applications[0], stage(1), {}, () => undefined);
    api.move$.error(new HttpErrorResponse({ status: 403 }));
    expect(api.fileCalls).toEqual([]);
    expect(store.filed().get(10)).toBe(7);
  });

  it('ignores a late personal board of the previous vacancy', () => {
    const { store, api } = setup(BoardStore);
    const late = new Subject<PersonalBoard>();
    api.personalBoard = () => late;
    store.load(1, true);
    api.personalBoard = () => of<PersonalBoard>({ columns: [], cards: [], layout: [] });
    store.load(2, true);
    late.next({ columns: [{ id: 99, title: 'old', color: null, position: 0, hidden: false }], cards: [], layout: ['col:99'] });
    expect(store.personal()?.columns).toEqual([]);
  });

  it('ignores a late new column and a late rollback of the previous vacancy', () => {
    const { store, api } = setup(BoardStore);
    const column$ = new Subject<PersonalColumn>();
    const layout$ = new Subject<string[]>();
    api.column$ = column$;
    api.layout$ = layout$;
    store.load(1, true);
    store.addColumn(1, 'Старе', () => undefined);
    store.moveLane(1, 3, 0, () => undefined);
    api.personalBoard = () => of<PersonalBoard>({ columns: [], cards: [], layout: [] });
    store.load(2, true);
    let key = '';
    column$.next({ id: 50, title: 'Старе', color: null, position: 1, hidden: false });
    layout$.error(new HttpErrorResponse({ status: 422, error: { code: 'board_layout_invalid' } }));
    expect(store.personal()).toEqual({ columns: [], cards: [], layout: [] });
    expect(key).toBe('');
    // The same calls on the current vacancy still apply.
    api.column$ = null;
    store.addColumn(2, 'Нове', (k) => (key = k));
    expect(store.personalColumns().map((c) => c.column.title)).toEqual(['Нове']);
  });

  it('a newer load cancels the board request of the previous vacancy', () => {
    const { store, api } = setup(BoardStore);
    const late = new Subject<Board>();
    api.board$ = late;
    store.load(1);
    api.board$ = of({ vacancy: { id: 2, stages: [] }, applications: [] } as unknown as Board);
    store.load(2);
    expect(late.observed).toBe(false);
    expect(store.board()?.vacancy.id).toBe(2);
  });

  it('plans a drop: own column files, a reject stage asks for a reason, readers cannot change the stage', () => {
    const { store } = setup(BoardStore);
    const app = application(10, 1);
    const column: PersonalColumn = { id: 7, title: 'Топ', color: null, position: 0, hidden: false };
    const reject = stage(3, { is_reject: true });
    expect(store.planMove(app, { type: 'personal', column }, false)).toBe('file');
    expect(store.planMove(app, { type: 'stage', stage: stage(2) }, true)).toBe('move');
    expect(store.planMove(app, { type: 'stage', stage: reject }, true)).toBe('reason');
    expect(store.planMove(app, { type: 'stage', stage: stage(2) }, false)).toBe('forbidden');
    // Dropping back on its own stage is never a change: no rights or reason needed.
    expect(store.planMove(app, { type: 'stage', stage: stage(1) }, false)).toBe('move');
  });

  it('flags a failed load', () => {
    const { store, api } = setup(BoardStore);
    api.board$ = throwError(() => new Error('down'));
    store.load(1);
    expect(store.failed()).toBe(true);
  });
});

describe('CandidatesStore', () => {
  it('filters reset the page', () => {
    const { store } = setup(CandidatesStore);
    store.setPage(3, 50);
    store.patchQuery({ status: 'active' });
    expect(store.query()).toEqual({ page: 1, perPage: 50, status: 'active' });
  });

  it('a newer query cancels the request in flight: its late answer never lands', () => {
    const { store, api } = setup(CandidatesStore);
    const late = new Subject<Paged<Candidate>>();
    api.candidates$ = late;
    store.load();
    expect(store.loading()).toBe(true);
    api.candidates$ = of(page([candidate(9)]));
    store.patchQuery({ status: 'active' });
    expect(late.observed).toBe(false);
    expect(store.items().map((c) => c.id)).toEqual([9]);
    expect(store.total()).toBe(1);
    expect(store.loading()).toBe(false);
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

  it('opening another candidate cancels the previous request', () => {
    const { store, api } = setup(CandidateCardStore);
    const late = new Subject<Candidate>();
    api.candidate = () => late;
    store.open(7);
    api.candidate = (id: number) => of(candidate(id));
    store.open(8);
    expect(late.observed).toBe(false);
    expect(store.candidate()?.id).toBe(8);
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

  it('takes the active counter from the page meta', () => {
    const { store, api } = setup(VacanciesStore);
    api.vacancies = () => of({ data: [], meta: { current_page: 1, per_page: 50, total: 0, last_page: 1, active_count: 4 } });
    store.load();
    expect(store.activeCount()).toBe(4);
  });
});

describe('ReportsStore', () => {
  it('loads all reports and makes one funnel card per vacancy, most active first', () => {
    const { store } = setup(ReportsStore);
    store.load();
    expect(store.funnelCards().map((c) => [c.title, c.stages.length, c.active])).toEqual([
      ['V2', 1, 4],
      ['V1', 2, 3],
    ]);
    expect(store.funnelCards()[1].stages.map((st) => st.share)).toEqual([67, 33]);
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
