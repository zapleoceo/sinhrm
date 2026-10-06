import { TestBed } from '@angular/core/testing';
import { Observable, of, Subject } from 'rxjs';
import { BoardStore } from './board/board.store';
import { CandidatesStore } from './candidates/candidates.store';
import { Application, Board, Candidate, CandidateQuery, Paged, PersonalBoard, Stage } from './recruiting.model';
import { RecruitingService } from './recruiting.service';

const stage = (id: number): Stage => ({ id, name: `S${id}`, kind: 'select', position: id, is_terminal: false, is_reject: false, is_hire: false });
const application = (id: number, stageId: number, score: number | null): Application => ({
  id, candidate_id: id, vacancy_id: 1, stage_id: stageId, screening_score: score, status: 'active',
  reject_reason_id: null, reject_reason: null, rejected_note: null, stage_entered_at: null,
  last_touch_at: null, is_stale: false, closed_at: null, created_at: null,
});

function boardSetup(): { store: BoardStore; api: { move: ReturnType<typeof vi.fn>; screen: ReturnType<typeof vi.fn>; move$: Subject<Application> } } {
  const move$ = new Subject<Application>();
  const api = { move: vi.fn(() => move$), screen: vi.fn(), move$ };
  TestBed.configureTestingModule({ providers: [BoardStore, { provide: RecruitingService, useValue: api }] });
  const store = TestBed.inject(BoardStore);
  store.board.set({ vacancy: { id: 1, stages: [stage(1), stage(2)] }, applications: [
    application(1, 1, null), application(2, 1, 0), application(3, 1, 82), application(4, 1, 82),
    application(5, 2, 0), application(6, 2, 100), application(7, 2, null),
  ] } as Board);
  return { store, api };
}

describe('screening ranking store integration', () => {
  it('ranks stage and personal lanes independently while preserving layout, membership and default order', () => {
    const { store, api } = boardSetup();
    const personal: PersonalBoard = {
      columns: [{ id: 7, title: 'Top', color: null, position: 0, hidden: false }],
      cards: [{ application_id: 5, column_id: 7 }, { application_id: 6, column_id: 7 }, { application_id: 7, column_id: 7 }],
      layout: ['stage:1', 'col:7', 'stage:2'],
    };
    store.personal.set(personal);
    const board = store.board();
    const ids = (): number[][] => store.lanes().map((lane) => lane.items.map((card) => card.id));
    expect(ids()).toEqual([[1, 2, 3, 4], [5, 6, 7], []]);
    store.rankScreening.set(true);
    expect(ids()).toEqual([[3, 4, 2, 1], [6, 5, 7], []]);
    expect(store.lanes().map((lane) => lane.key)).toEqual(personal.layout);
    expect(store.board()).toBe(board);
    expect(store.personal()).toBe(personal);
    expect(store.board()!.applications.map((card) => card.stage_id)).toEqual([1, 1, 1, 1, 2, 2, 2]);
    store.rankScreening.set(false);
    expect(ids()).toEqual([[1, 2, 3, 4], [5, 6, 7], []]);
    expect(api.screen).not.toHaveBeenCalled();
    expect(api.move).not.toHaveBeenCalled();
  });

  it('keeps the saved score through a stage move response which contains no ranking projection', () => {
    const { store, api } = boardSetup();
    store.rankScreening.set(true);
    store.move(store.board()!.applications[2], stage(2), {}, () => undefined);
    expect(store.lanes()[1].items.map((card) => card.id)).toEqual([6, 3, 5, 7]);
    api.move$.next(application(3, 2, null));
    expect(store.lanes()[1].items.map((card) => card.id)).toEqual([6, 3, 5, 7]);
    expect(store.board()!.applications.find((card) => card.id === 3)?.screening_score).toBe(82);
    expect(api.screen).not.toHaveBeenCalled();
  });

  it('sends list ranking to the API, resets pagination and restores server default order when disabled', () => {
    const candidates = vi.fn((query: CandidateQuery): Observable<Paged<Candidate>> => of({
      data: (query.sort ? [{ id: 1 }, { id: 2 }] : [{ id: 2 }, { id: 1 }]) as Candidate[],
      meta: { current_page: query.page ?? 1, per_page: query.perPage ?? 50, total: 2, last_page: 1 },
    }));
    const screen = vi.fn();
    TestBed.configureTestingModule({ providers: [CandidatesStore, { provide: RecruitingService, useValue: { candidates, screen } }] });
    const store = TestBed.inject(CandidatesStore);
    store.query.set({ page: 3, perPage: 20, status: 'active', vacancy_id: 4, q: 'synthetic' });
    store.patchQuery({ sort: 'screening_score' });
    expect(candidates).toHaveBeenLastCalledWith({ page: 1, perPage: 20, status: 'active', vacancy_id: 4, q: 'synthetic', sort: 'screening_score' });
    expect(store.items().map((card) => card.id)).toEqual([1, 2]);
    store.setPage(2, 20);
    expect(candidates).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2, sort: 'screening_score' }));
    store.patchQuery({ sort: undefined });
    expect(candidates).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1, sort: undefined, vacancy_id: 4 }));
    expect(store.items().map((card) => card.id)).toEqual([2, 1]);
    expect(screen).not.toHaveBeenCalled();
  });
});
