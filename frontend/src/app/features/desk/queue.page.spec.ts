import { of } from 'rxjs';
import { TablePage } from '../../../testing/table';
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
  let page: TablePage;

  beforeEach(async () => {
    queries = [];
    const api = { queue: (q: QueueQuery) => (queries.push(q), of(CASES)), categories: () => of([{ id: 1, name: 'Довідки', active: true }]) };
    page = await TablePage.open('desk/queue', DeskQueuePage, '/desk/queue', [{ provide: DeskService, useValue: api }]);
  });

  it('opens with open cases (status in the header), API order and no arrow', () => {
    expect(queries).toEqual([{ open: true, category_id: undefined }]);
    expect(page.th('desk.statusLabel').querySelector('.dot')).not.toBeNull();
    expect(page.th('#').getAttribute('aria-sort')).toBe('none');
    expect(page.column(0, 'table.queue')).toEqual(['7', '9', '8']);
  });

  it('title clicks sort on the page (asc, then desc) without a new request; SLA sorts breached first', async () => {
    await page.sort('#');
    expect(page.params.get('sort')).toBe('id');
    expect(page.column(0, 'table.queue')).toEqual(['7', '8', '9']);
    await page.sort('#');
    expect(page.params.get('dir')).toBe('desc');
    expect(page.column(0, 'table.queue')).toEqual(['9', '8', '7']);
    await page.sort('SLA');
    expect(page.column(0, 'table.queue')).toEqual(['9', '7', '8']);
    expect(queries).toHaveLength(1);
  });

  it('status and category in the URL go to the API; «all» drops the status; the employee filter works on the page', async () => {
    await page.navigate('/desk/queue?status=waiting&category=1');
    expect(queries.at(-1)).toEqual({ status: 'waiting', category_id: 1 });
    await page.navigate('/desk/queue?status=all&employee=бой');
    expect(queries.at(-1)).toEqual({ category_id: undefined });
    expect(page.th('desk.statusLabel').querySelector('.dot')).toBeNull();
    expect(page.column(2, 'table.queue')).toEqual(['Бойко']);
    await page.navigate('/desk/queue?status=junk');
    expect(queries.at(-1)).toEqual({ open: true, category_id: undefined });
  });
});
