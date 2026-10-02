import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Subject, of } from 'rxjs';
import { convertToParamMap } from '@angular/router';
import { PEOPLE_PAGE_SIZE, peopleQueryFromParams, sameQuery } from './directory/people.query';
import { PeopleStore } from './directory/people.store';
import { countNodes, expandedToDepth, filterTree, initials } from './org-tree';
import { canManagePeople } from './people.access';
import { Employee, OrgNode, Paged, fieldLabelKey } from './people.model';
import { PeopleService, diffChanges, peopleErrorKey } from './people.service';
import { profileTabs } from './profile/profile.store';

const EMPTY = { data: [], meta: { current_page: 1, per_page: 50, total: 0, last_page: 1 } };
const node = (id: number, name: string, reports: OrgNode[] = [], position: string | null = null): OrgNode => ({
  id,
  full_name: name,
  avatar_url: null,
  position: position === null ? null : { id: 1, name: position },
  department: null,
  branch: null,
  reports_count: reports.length,
  reports,
});
const employee = (access?: Employee['access']): Employee =>
  ({ id: 1, full_name: 'A', status: 'active', access }) as Employee;

describe('PeopleService', () => {
  let api: PeopleService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    api = TestBed.inject(PeopleService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('lists people with string params and without empty filters', () => {
    api.list({ q: '', branch_id: 3, perPage: 20 }).subscribe();
    const req = http.expectOne((r) => r.url === '/api/people');
    expect(req.request.params.get('branch_id')).toBe('3');
    expect(req.request.params.get('perPage')).toBe('20');
    expect(req.request.params.has('q')).toBe(false);
    req.flush(EMPTY);
  });

  it('asks for "my team" only when requested', () => {
    api.orgChart({ mine: true }).subscribe();
    const mine = http.expectOne((r) => r.url === '/api/people/org-chart');
    expect(mine.request.params.get('mine')).toBe('1');
    mine.flush({ data: [] });

    api.orgChart().subscribe();
    const all = http.expectOne((r) => r.url === '/api/people/org-chart');
    expect(all.request.params.keys()).toEqual([]);
    all.flush({ data: [] });
  });

  it('decides change requests and hires from an application', () => {
    api.decideChange(7, false, 'no').subscribe();
    const reject = http.expectOne('/api/people/change-requests/7/reject');
    expect(reject.request.body).toEqual({ comment: 'no' });
    reject.flush({ data: {} });

    let created: boolean | undefined;
    api.hire(12).subscribe((r) => (created = r.created));
    const hire = http.expectOne('/api/applications/12/hire');
    expect(hire.request.body).toEqual({});
    hire.flush({ data: { id: 5 }, meta: { created: false } });
    expect(created).toBe(false);
  });

  it('maps errors to i18n keys', () => {
    const err = (status: number, code?: string) => new HttpErrorResponse({ status, error: code ? { code } : null });
    expect(peopleErrorKey(err(404, 'no_employee'))).toBe('people.errors.no_employee');
    expect(peopleErrorKey(err(422, 'manager_cycle'))).toBe('people.errors.manager_cycle');
    expect(peopleErrorKey(err(403))).toBe('people.errors.forbidden');
    expect(peopleErrorKey(err(422))).toBe('people.errors.validation');
    expect(peopleErrorKey(err(500))).toBe('common.error');
    expect(peopleErrorKey(new Error('x'))).toBe('common.error');
  });
});

describe('People helpers', () => {
  it('sends only changed contact fields', () => {
    expect(diffChanges({ phone: '1', address: null }, { phone: ' 1 ', address: '', personal_email: 'a@example.test' })).toEqual({
      personal_email: 'a@example.test',
    });
    expect(diffChanges({ phone: '1' }, { phone: '' })).toEqual({ phone: null });
  });

  it('builds field label keys', () => {
    expect(fieldLabelKey('personal_email')).toBe('people.fields.personalEmail');
    expect(fieldLabelKey('phone')).toBe('people.fields.phone');
  });

  it('shows profile tabs by access flags', () => {
    expect(profileTabs(null)).toEqual([]);
    expect(profileTabs(employee())).toEqual(['overview']);
    expect(profileTabs(employee({ job: true, pii: false, decide: true, manage: false, self: false }))).toEqual([
      'overview',
      'job',
      'timeoff',
      'changes',
      'documents',
      'workflows',
      'performance',
      'assets',
    ]);
    expect(profileTabs(employee({ job: true, pii: true, decide: false, manage: false, self: true }))).toEqual([
      'overview',
      'job',
      'timeoff',
      'changes',
      'documents',
      'performance',
      'assets',
    ]);
    expect(profileTabs(employee({ job: true, pii: true, decide: true, manage: true, self: true }))).toContain('workflows');
  });

  it('knows who manages people', () => {
    expect(canManagePeople(['admin'])).toBe(true);
    expect(canManagePeople(['hr_manager'])).toBe(true);
    expect(canManagePeople(['recruiter', 'viewer', 'employee'])).toBe(false);
  });

  it('walks, filters and counts the org tree', () => {
    const tree = [node(1, 'Head Person', [node(2, 'Lead Person', [node(3, 'Worker', [], 'Tutor')])]), node(4, 'Other')];
    expect(countNodes(tree)).toBe(4);
    expect([...expandedToDepth(tree, 0)]).toEqual([1]);
    expect([...expandedToDepth(tree, 5)].sort()).toEqual([1, 2]);

    const found = filterTree(tree, 'tutor');
    expect(found.nodes.map((n) => n.id)).toEqual([1]);
    expect(found.nodes[0].reports[0].reports[0].full_name).toBe('Worker');
    expect([...found.open].sort()).toEqual([1, 2]);
    expect(filterTree(tree, '  ').nodes.length).toBe(2);
    expect(initials('Ivan  Petrenko Jr')).toBe('IP');
  });
});

describe('PeopleStore', () => {
  it('cancels the stale request (not only its answer) and does not reload the same query', () => {
    const first = new Subject<Paged<Employee>>();
    const second = new Subject<Paged<Employee>>();
    const calls = [first, second];
    const list = vi.fn(() => calls.shift() ?? of(EMPTY));
    TestBed.configureTestingModule({ providers: [PeopleStore, { provide: PeopleService, useValue: { list } }] });
    const store = TestBed.inject(PeopleStore);

    store.apply({ page: 3, perPage: 20 });
    store.apply({ q: 'ann', page: 1, perPage: 20 });
    expect(store.query().page).toBe(1);
    // The first request is unsubscribed (HttpClient aborts it), the second one is live.
    expect(first.observed).toBe(false);
    expect(second.observed).toBe(true);
    second.next({ data: [employee()], meta: { current_page: 1, per_page: 20, total: 1, last_page: 1 } });
    first.next({ data: [], meta: { current_page: 3, per_page: 20, total: 0, last_page: 1 } });
    expect(store.items().length).toBe(1);
    expect(store.total()).toBe(1);
    expect(store.loading()).toBe(false);

    store.apply({ q: 'ann', page: 1, perPage: 20 });
    expect(list).toHaveBeenCalledTimes(2);

    store.setView('cards');
    expect(store.view()).toBe('cards');
  });
});

describe('people query in the URL', () => {
  it('reads filters, sort and paging from the URL; junk is dropped, not sent to the API', () => {
    const q = peopleQueryFromParams(
      convertToParamMap({ q: ' ann ', name: 'Ko', manager: 'lead', position_id: '3', status: 'terminated', sort: 'manager', dir: 'desc', page: '2', perPage: '20' }),
    );
    expect(q).toEqual({
      q: 'ann',
      name: 'Ko',
      contact: undefined,
      manager: 'lead',
      branch_id: undefined,
      department_id: undefined,
      position_id: 3,
      status: 'terminated',
      sort: 'manager',
      dir: 'desc',
      page: 2,
      perPage: 20,
    });
    const junk = peopleQueryFromParams(convertToParamMap({ sort: 'full_name', status: 'fired', branch_id: 'x', page: '-1', perPage: '9999' }));
    expect(junk.sort).toBeUndefined();
    expect(junk.dir).toBeUndefined();
    expect(junk.status).toBeUndefined();
    expect(junk.branch_id).toBeUndefined();
    expect(junk.page).toBe(1);
    expect(junk.perPage).toBe(200);
    expect(peopleQueryFromParams(convertToParamMap({})).perPage).toBe(PEOPLE_PAGE_SIZE);
    // A hand-edited URL longer than the API limit (max:100) is cut, so the API never answers 422.
    expect(peopleQueryFromParams(convertToParamMap({ manager: 'x'.repeat(150) })).manager).toHaveLength(100);
  });

  it('compares queries by value', () => {
    expect(sameQuery({ q: 'a', page: 1 }, { page: 1, q: 'a', name: undefined })).toBe(true);
    expect(sameQuery({ sort: 'name', dir: 'asc' }, { sort: 'name', dir: 'desc' })).toBe(false);
  });

  it('the service sends sort, dir and the column filters as query params', () => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    const http = TestBed.inject(HttpTestingController);
    TestBed.inject(PeopleService).list({ name: 'Ko', contact: '050', manager: 'lead', sort: 'position', dir: 'desc', perPage: 20 }).subscribe();
    const req = http.expectOne((r) => r.url === '/api/people');
    expect(req.request.params.get('sort')).toBe('position');
    expect(req.request.params.get('dir')).toBe('desc');
    expect(req.request.params.get('name')).toBe('Ko');
    expect(req.request.params.get('contact')).toBe('050');
    expect(req.request.params.get('manager')).toBe('lead');
    req.flush(EMPTY);
    http.verify();
  });
});
