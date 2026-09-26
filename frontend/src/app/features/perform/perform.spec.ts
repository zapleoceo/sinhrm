import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import {
  Objective,
  OneOnOne,
  itemsBody,
  keyResultRatio,
  newItem,
  objectiveTree,
  parseIds,
  progressTone,
  quarterOf,
  quarterOptions,
  scoreWidth,
  splitMeetings,
  toggleItem,
} from './perform.model';
import { PerformService, performErrorKey } from './perform.service';

const objective = (id: number, parent: number | null, extra: Partial<Objective> = {}): Objective => ({
  id,
  scope: 'personal',
  owner: { id: 1, full_name: 'Test Person' },
  period: '2026-Q4',
  title: `O${id}`,
  description: null,
  key_results: [],
  progress: 0,
  status: 'active',
  parent_objective_id: parent,
  visibility: 'public',
  can_edit: true,
  ...extra,
});

const meeting = (id: number, at: string, status: OneOnOne['status'] = 'scheduled'): OneOnOne => ({
  id,
  manager: { id: 1, full_name: 'Lead' },
  employee: { id: 2, full_name: 'Worker' },
  scheduled_at: at,
  template_id: null,
  status,
  agenda: [],
  notes_shared: null,
  action_items: [],
  can_manage: true,
  can_private_notes: true,
});

describe('perform model', () => {
  it('computes quarters', () => {
    expect(quarterOf(new Date(2026, 9, 5))).toBe('2026-Q4');
    expect(quarterOf(new Date(2026, 0, 1))).toBe('2026-Q1');
    expect(quarterOptions(new Date(2026, 9, 5), 1, 1)).toEqual(['2026-Q3', '2026-Q4', '2027-Q1']);
  });

  it('mirrors the server key result formula', () => {
    expect(keyResultRatio({ start: 0, target: 10, current: 5 })).toBe(0.5);
    expect(keyResultRatio({ start: 10, target: 0, current: 5 })).toBe(0.5);
    expect(keyResultRatio({ start: 0, target: 10, current: 20 })).toBe(1);
    expect(keyResultRatio({ start: 5, target: 5, current: 4 })).toBe(0);
  });

  it('builds the alignment tree depth-first; invisible parents make roots', () => {
    const tree = objectiveTree([objective(3, 1), objective(1, null), objective(2, 1), objective(4, 99), objective(5, 3)]);
    expect(tree.map((n) => [n.objective.id, n.depth])).toEqual([
      [1, 0],
      [3, 1],
      [5, 2],
      [2, 1],
      [4, 0],
    ]);
  });

  it('survives an alignment loop in bad data', () => {
    expect(objectiveTree([objective(1, 2), objective(2, 1)])).toEqual([]);
  });

  it('bands and widths', () => {
    expect(progressTone(10)).toBe('danger');
    expect(progressTone(50)).toBe('warning');
    expect(progressTone(90)).toBe('success');
    expect(scoreWidth(2.5, 5)).toBe(50);
    expect(scoreWidth(null, 5)).toBe(0);
  });

  it('edits list items for the API', () => {
    const items = [newItem('  Plan  '), { id: 'a1', text: 'Done', done: false }];
    expect(items[0]).toEqual({ id: '', text: 'Plan', done: false, due_on: null });
    expect(toggleItem(items, 'a1')[1].done).toBe(true);
    expect(itemsBody(items)).toEqual([
      { text: 'Plan', done: false, due_on: null },
      { id: 'a1', text: 'Done', done: false },
    ]);
  });

  it('splits meetings into upcoming and past', () => {
    const now = new Date(2026, 9, 5, 15, 0);
    const { upcoming, past } = splitMeetings(
      [meeting(1, '2026-10-20T10:00:00Z'), meeting(2, '2026-10-05T09:00:00Z'), meeting(3, '2026-10-01T10:00:00Z'), meeting(4, '2026-10-30T10:00:00Z', 'cancelled')],
      now,
    );
    expect(upcoming.map((m) => m.id)).toEqual([2, 1]);
    expect(past.map((m) => m.id)).toEqual([4, 3]);
  });

  it('parses id lists', () => {
    expect(parseIds('1, 2 3;3 x -1 0')).toEqual([1, 2, 3]);
    expect(parseIds('')).toEqual([]);
  });
});

describe('PerformService', () => {
  let service: PerformService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    service = TestBed.inject(PerformService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('lists objectives of a period and posts check-ins', () => {
    service.objectives({ period: '2026-Q4' }).subscribe((list) => expect(list.length).toBe(1));
    http.expectOne((r) => r.url === '/api/perform/objectives' && r.params.get('period') === '2026-Q4').flush({ data: [objective(1, null)] });

    service.checkIn(1, [{ id: 'kr1', current: 5 }], 'ok').subscribe();
    const req = http.expectOne('/api/perform/objectives/1/check-ins');
    expect(req.request.body).toEqual({ key_results: [{ id: 'kr1', current: 5 }], comment: 'ok' });
    req.flush({ data: objective(1, null) });
  });

  it('patches 1:1s and submits reviews', () => {
    service.updateOneOnOne(3, { notes_shared: 'x' }).subscribe();
    const patch = http.expectOne('/api/perform/one-on-ones/3');
    expect(patch.request.method).toBe('PATCH');
    patch.flush({ data: meeting(3, '2026-10-05T10:00:00Z') });

    service.submitReview(9, [{ competency_id: 1, rating: 3 }]).subscribe();
    const submit = http.expectOne('/api/perform/review/assignments/9/submit');
    expect(submit.request.body).toEqual({ answers: [{ competency_id: 1, rating: 3 }] });
    submit.flush({ data: {} });

    service.feedback('team').subscribe();
    http.expectOne((r) => r.url === '/api/perform/feedback' && r.params.get('box') === 'team').flush({ data: [] });
  });

  it('maps errors to i18n keys', () => {
    expect(performErrorKey(new HttpErrorResponse({ status: 409, error: { code: 'already_submitted' } }))).toBe('perform.errors.already_submitted');
    expect(performErrorKey(new HttpErrorResponse({ status: 403 }))).toBe('perform.errors.forbidden');
    expect(performErrorKey(new HttpErrorResponse({ status: 404 }))).toBe('perform.errors.not_found');
    expect(performErrorKey(new HttpErrorResponse({ status: 422, error: { code: 'nope' } }))).toBe('perform.errors.validation');
    expect(performErrorKey(new Error('x'))).toBe('common.error');
  });
});
