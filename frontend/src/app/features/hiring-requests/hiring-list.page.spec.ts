import { of } from 'rxjs';
import { TablePage, clickTitle, header, openTablePage, sortCount } from '../../../testing/table-page';
import { HiringListPage } from './hiring-list.page';
import { HiringRequest, HiringStatus } from './hiring-requests.model';
import { HiringRequestsService } from './hiring-requests.service';

const request = (id: number, title: string, status: HiringStatus, created: string): HiringRequest =>
  ({
    id,
    title,
    status,
    priority: 'normal',
    headcount: 1,
    branch: { id: 1, name: 'Київ' },
    requester: { id: 1, name: 'Олена' },
    current_step: null,
    progress: null,
    vacancy: null,
    overdue: false,
    created_at: created,
  }) as unknown as HiringRequest;

const LIST = [request(1, 'Бухгалтер', 'pending', '2026-09-10T08:00:00Z'), request(2, 'Аналітик', 'approved', '2026-09-20T08:00:00Z'), request(3, 'Юрист', 'pending', '2026-08-01T08:00:00Z')];

describe('HiringListPage: sortable / filterable headers bound to the URL', () => {
  let calls: { status?: HiringStatus; mine?: boolean }[];
  let page: TablePage<HiringListPage>;
  const table = () => page.el.querySelector('table')!;
  const titles = () => [...table().querySelectorAll('tbody tr td:first-child a')].map((a) => a.textContent?.trim());

  beforeEach(async () => {
    calls = [];
    const api = {
      list: (q: { status?: HiringStatus; mine?: boolean }) => (calls.push(q), of(LIST)),
      inbox: () => of(LIST),
      meta: () => of({ can_create: false, can_manage: false, form_fields: [] }),
    };
    page = await openTablePage(HiringListPage, '/?status=pending&sort=created&dir=desc', [{ provide: HiringRequestsService, useValue: api }]);
  });

  it('sends the status from the URL to the API once and shows the sort on its header', () => {
    expect(calls).toEqual([{ status: 'pending', mine: false }]);
    expect(header(table(), 'hiring.fields.created').getAttribute('aria-sort')).toBe('descending');
    // The mock ignores the status: the page also matches it (the inbox API has no status filter).
    expect(titles()).toEqual(['Бухгалтер', 'Юрист']);
  });

  it('a title click sorts by that column; the date range filters on the page', async () => {
    clickTitle(table(), 'hiring.fields.title');
    await page.settle();
    expect(new URL(page.router.url, 'http://x').searchParams.get('sort')).toBe('title');
    expect(titles()).toEqual(['Бухгалтер', 'Юрист']);
    await page.router.navigateByUrl('/?created_from=2026-09-01');
    await page.settle();
    expect(calls.at(-1)).toEqual({ status: undefined, mine: false });
    expect(titles()).toEqual(['Бухгалтер', 'Аналітик']);
    expect(sortCount(page.fixture)).toBe(2); // what an open header filter announces
  });
});
