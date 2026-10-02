import { provideNativeDateAdapter } from '@angular/material/core';
import { of } from 'rxjs';
import { TablePage, clickTitle, openTablePage } from '../../../../testing/table-page';
import { CycleStatus, ReviewCycle } from '../perform.model';
import { PerformService } from '../perform.service';
import { ReviewAdminPage } from './review-admin.page';

const cycle = (id: number, name: string, status: CycleStatus, start: string, submitted: number, total: number): ReviewCycle => ({
  id,
  name,
  period_start: start,
  period_end: start,
  participants: { branch_ids: [], department_ids: [] },
  types: ['self'],
  competency_ids: [],
  anonymous: true,
  deadlines: {},
  status,
  progress: { submitted, total },
});

const CYCLES = [cycle(1, 'Q3 2026', 'active', '2026-07-01', 5, 10), cycle(2, 'Q2 2026', 'closed', '2026-04-01', 10, 10), cycle(3, 'Річна 2026', 'draft', '2026-12-01', 0, 0)];

describe('ReviewAdminPage cycles: a real header row that sorts and filters', () => {
  let page: TablePage<ReviewAdminPage>;
  const table = (sel = 'table') => page.el.querySelector(sel)!;
  const sort = async (title: string, sel = 'table') => {
    clickTitle(table(sel), title);
    await page.settle();
  };
  const navigate = async (url: string) => {
    await page.router.navigateByUrl(url);
    await page.settle();
  };
  const params = () => new URL(page.router.url, 'http://x').searchParams;
  const cells = (index: number, sel = 'table', inner?: string) =>
    [...table(sel).querySelectorAll('tbody tr')].map((tr) => (inner ? tr.children[index]?.querySelector(inner) : tr.children[index])?.textContent?.trim() ?? '');

  beforeEach(async () => {
    const api = { scales: () => of([]), competencies: () => of([]), cycles: () => of(CYCLES) };
    page = await openTablePage(ReviewAdminPage, '/', [{ provide: PerformService, useValue: api }, provideNativeDateAdapter()]);
  });

  it('has column headers; progress sorts by the submitted share (no assignments last)', async () => {
    expect(page.el.querySelectorAll('table.cycles thead th[scope="col"]')).toHaveLength(5);
    await sort('perform.admin.progress');
    expect(params().get('sort')).toBe('progress');
    expect(cells(0, 'table.cycles')).toEqual(['Q3 2026', 'Q2 2026', 'Річна 2026']);
    await sort('perform.admin.progress');
    expect(cells(0, 'table.cycles')).toEqual(['Q2 2026', 'Q3 2026', 'Річна 2026']);
  });

  it('status and period filters from the URL', async () => {
    await navigate('/?status=closed');
    expect(cells(0, 'table.cycles')).toEqual(['Q2 2026']);
    await navigate('/?period_from=2026-06-01');
    expect(cells(0, 'table.cycles')).toEqual(['Q3 2026', 'Річна 2026']);
  });
});
