import { MatDialog } from '@angular/material/dialog';
import { of } from 'rxjs';
import { TablePage, clickTitle, header, openTablePage, sortCount } from '../../../../testing/table-page';
import { WorkflowTemplate } from '../workflows.model';
import { WorkflowsService } from '../workflows.service';
import { WorkflowTemplatesPage } from './workflow-templates.page';

const template = (id: number, name: string, kind: WorkflowTemplate['kind'], runs: number, steps: number, updated: string): WorkflowTemplate =>
  ({ id, name, kind, trigger: 'manual', active: true, probation_days: null, runs_count: runs, updated_at: updated, steps: Array.from({ length: steps }, () => ({})) }) as unknown as WorkflowTemplate;

const TEMPLATES = [template(1, 'Онбординг ІТ', 'onboarding', 12, 5, '2026-09-01T00:00:00Z'), template(2, 'Звільнення', 'offboarding', 3, 8, '2026-09-20T00:00:00Z'), template(3, 'Адаптація', 'onboarding', 0, 2, '2026-07-10T00:00:00Z')];

describe('WorkflowTemplatesPage: sortable / filterable headers bound to the URL', () => {
  let page: TablePage<WorkflowTemplatesPage>;
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
    const api = { templates: () => of(TEMPLATES) };
    page = await openTablePage(WorkflowTemplatesPage, '/', [
      { provide: WorkflowsService, useValue: api },
      { provide: MatDialog, useValue: {} },
    ]);
  });

  it('keeps the API order until a title click; runs sort as numbers, both ways', async () => {
    expect(header(table(), 'workflows.fields.runs').getAttribute('aria-sort')).toBe('none');
    expect(cells(0, 'table', 'a')).toEqual(['Онбординг ІТ', 'Звільнення', 'Адаптація']);
    await sort('workflows.fields.runs');
    expect(params().get('sort')).toBe('runs');
    expect(cells(4)).toEqual(['0', '3', '12']);
    await sort('workflows.fields.runs');
    expect(cells(4)).toEqual(['12', '3', '0']);
  });

  it('kind, step-count range and date range filters from the URL', async () => {
    await navigate('/?kind=onboarding&steps_from=3');
    expect(cells(0, 'table', 'a')).toEqual(['Онбординг ІТ']);
    await navigate('/?updated_from=2026-08-01&updated_to=2026-09-30');
    expect(cells(0, 'table', 'a')).toEqual(['Онбординг ІТ', 'Звільнення']);
    expect(sortCount(page.fixture)).toBe(2); // what an open header filter announces
  });
});
