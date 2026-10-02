import { of } from 'rxjs';
import { TablePage, clickTitle, column, header, openTablePage } from '../../../testing/table-page';
import { CaseStatus, DeskCase, DeskCategory, QueueQuery } from './desk.model';
import { DeskService } from './desk.service';
import { DeskQueuePage } from './queue.page';

const sla = { first_response_due: null, resolve_due: null, first_response_breached: false, resolve_breached: false };
const deskCase = (id: number, subject: string, employee: string, status: CaseStatus, breached = false): DeskCase => ({
  id,
  subject,
  status,
  category: { id: 1, name: 'Довідки' },
  employee: { id, full_name: employee },
  assignee: null,
  created_at: '2026-10-01T09:00:00Z',
  first_response_at: null,
  resolved_at: null,
  closed_at: null,
  sla: { ...sla, resolve_breached: breached },
  can_manage: true,
});

const CASES = [deskCase(7, 'Відпустка', 'Яременко', 'new'), deskCase(9, 'Довідка 2-ПДФО', 'Бойко', 'waiting', true), deskCase(8, 'Аванс', 'Коваль', 'in_progress')];

describe('DeskQueuePage: sortable / filterable headers bound to the URL', () => {
  let queries: QueueQuery[];
  let page: TablePage<DeskQueuePage>;
  const table = () => page.el.querySelector('table.queue')!;
  const sort = async (title: string) => {
    clickTitle(table(), title);
    await page.settle();
  };
  const navigate = async (url: string) => {
    await page.router.navigateByUrl(url);
    await page.settle();
  };
  const params = () => new URL(page.router.url, 'http://x').searchParams;

  beforeEach(async () => {
    queries = [];
    const api = { queue: (q: QueueQuery) => (queries.push(q), of(CASES)), categories: () => of([{ id: 1, name: 'Довідки', active: true }]) };
    page = await openTablePage(DeskQueuePage, '/', [{ provide: DeskService, useValue: api }]);
  });

  it('opens with open cases (status in the header), API order and no arrow', () => {
    expect(queries).toEqual([{ open: true, category_id: undefined }]);
    expect(header(table(), 'desk.statusLabel').querySelector('.dot')).not.toBeNull();
    expect(header(table(), '#').getAttribute('aria-sort')).toBe('none');
    expect(column(table(), 0)).toEqual(['7', '9', '8']);
  });

  it('title clicks sort on the page (asc, then desc) without a new request; SLA sorts breached first', async () => {
    await sort('#');
    expect(params().get('sort')).toBe('id');
    expect(column(table(), 0)).toEqual(['7', '8', '9']);
    await sort('#');
    expect(params().get('dir')).toBe('desc');
    expect(column(table(), 0)).toEqual(['9', '8', '7']);
    await sort('SLA');
    expect(column(table(), 0)).toEqual(['9', '7', '8']);
    expect(queries).toHaveLength(1);
  });

  it('status and category in the URL go to the API; «all» drops the status; the employee filter works on the page', async () => {
    await navigate('/?status=waiting&category=1');
    expect(queries.at(-1)).toEqual({ status: 'waiting', category_id: 1 });
    await navigate('/?status=all&employee=бой');
    expect(queries.at(-1)).toEqual({ category_id: undefined });
    expect(header(table(), 'desk.statusLabel').querySelector('.dot')).toBeNull();
    expect(column(table(), 2)).toEqual(['Бойко']);
    await navigate('/?status=junk');
    expect(queries.at(-1)).toEqual({ open: true, category_id: undefined });
  });
});

const cat = (id: number, name: string, first: number | null, active = true): DeskCategory => ({
  id,
  name,
  first_response_hours: first,
  resolve_hours: first === null ? null : first * 3,
  default_assignee_id: null,
  active,
});

// API order: by name.
const categories = [cat(1, 'Відпустки', 8), cat(2, 'Довідки', null, false), cat(3, 'Зарплата', 4)];

function open(url: string): ReturnType<typeof openTablePage<DeskQueuePage>> {
  return openTablePage(DeskQueuePage, url, [{ provide: DeskService, useValue: { queue: () => of([]), categories: () => of(categories) } }]);
}

const catsTable = (el: HTMLElement): HTMLTableElement => el.querySelector('.cats table') as HTMLTableElement;

describe('DeskQueuePage categories table (header sort and filter)', () => {
  it('shows the API order with the arrow on the name; a click on «first response» sorts with empty last', async () => {
    const { el, router, settle } = await open('/');
    const table = catsTable(el);
    expect(header(table, 'desk.categories.name').getAttribute('aria-sort')).toBe('ascending');
    expect(column(table, 0)).toEqual(['Відпустки', 'Довідки', 'Зарплата']);
    clickTitle(table, 'desk.categories.firstResponse');
    await settle();
    expect(router.url).toBe('/?cat_sort=first&cat_dir=asc');
    expect(column(table, 0)).toEqual(['Зарплата', 'Відпустки', 'Довідки']);
    clickTitle(table, 'desk.categories.firstResponse');
    await settle();
    expect(column(table, 0)).toEqual(['Відпустки', 'Зарплата', 'Довідки']);
  });

  it('address → view: the active filter and a text filter come from the URL', async () => {
    const { el } = await open('/?cat_active=false');
    expect(column(catsTable(el), 0)).toEqual(['Довідки']);
    expect(header(catsTable(el), 'desk.categories.active').querySelector('button.filter.active')).not.toBeNull();
  });

  it('a filter that hides every category says so instead of an empty table', async () => {
    const { el } = await open('/?cat_name=zzz');
    expect(column(catsTable(el), 0)).toEqual(['table.noMatches']);
  });
});

describe('DeskQueuePage: the cases table and the categories table on one page', () => {
  it('each table keeps its own URL state; sorting categories does not reload the queue', async () => {
    const queries: QueueQuery[] = [];
    const api = { queue: (q: QueueQuery) => (queries.push(q), of(CASES)), categories: () => of(categories) };
    const { el, router, settle } = await openTablePage(DeskQueuePage, '/?sort=id&dir=desc', [{ provide: DeskService, useValue: api }]);
    clickTitle(catsTable(el), 'desk.categories.firstResponse');
    await settle();
    expect(router.url).toBe('/?sort=id&dir=desc&cat_sort=first&cat_dir=asc');
    expect(column(el.querySelector('table.queue')!, 0)).toEqual(['9', '8', '7']);
    expect(column(catsTable(el), 0)).toEqual(['Зарплата', 'Відпустки', 'Довідки']);
    expect(queries).toHaveLength(1);
  });
});
