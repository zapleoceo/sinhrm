import { of } from 'rxjs';
import { TablePage, clickTitle, column, header, openTablePage } from '../../../testing/table-page';
import { CaseStatus, DeskCase, QueueQuery } from './desk.model';
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
