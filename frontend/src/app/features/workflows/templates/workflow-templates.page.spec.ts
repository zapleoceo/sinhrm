import { MatDialog } from '@angular/material/dialog';
import { of } from 'rxjs';
import { TablePage } from '../../../../testing/table';
import { WorkflowTemplate } from '../workflows.model';
import { WorkflowsService } from '../workflows.service';
import { WorkflowTemplatesPage } from './workflow-templates.page';

const template = (id: number, name: string, kind: WorkflowTemplate['kind'], runs: number, steps: number, updated: string): WorkflowTemplate =>
  ({ id, name, kind, trigger: 'manual', active: true, probation_days: null, runs_count: runs, updated_at: updated, steps: Array.from({ length: steps }, () => ({})) }) as unknown as WorkflowTemplate;

const TEMPLATES = [template(1, 'Онбординг ІТ', 'onboarding', 12, 5, '2026-09-01T00:00:00Z'), template(2, 'Звільнення', 'offboarding', 3, 8, '2026-09-20T00:00:00Z'), template(3, 'Адаптація', 'onboarding', 0, 2, '2026-07-10T00:00:00Z')];

describe('WorkflowTemplatesPage: sortable / filterable headers bound to the URL', () => {
  let page: TablePage;

  beforeEach(async () => {
    const api = { templates: () => of(TEMPLATES) };
    page = await TablePage.open('admin/workflows', WorkflowTemplatesPage, '/admin/workflows', [
      { provide: WorkflowsService, useValue: api },
      { provide: MatDialog, useValue: {} },
    ]);
  });

  it('keeps the API order until a title click; runs sort as numbers, both ways', async () => {
    expect(page.th('workflows.fields.runs').getAttribute('aria-sort')).toBe('none');
    expect(page.column(0, 'table', 'a')).toEqual(['Онбординг ІТ', 'Звільнення', 'Адаптація']);
    await page.sort('workflows.fields.runs');
    expect(page.params.get('sort')).toBe('runs');
    expect(page.column(4)).toEqual(['0', '3', '12']);
    await page.sort('workflows.fields.runs');
    expect(page.column(4)).toEqual(['12', '3', '0']);
  });

  it('kind, step-count range and date range filters from the URL', async () => {
    await page.navigate('/admin/workflows?kind=onboarding&steps_from=3');
    expect(page.column(0, 'table', 'a')).toEqual(['Онбординг ІТ']);
    await page.navigate('/admin/workflows?updated_from=2026-08-01&updated_to=2026-09-30');
    expect(page.column(0, 'table', 'a')).toEqual(['Онбординг ІТ', 'Звільнення']);
  });
});
