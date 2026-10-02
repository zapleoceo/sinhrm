import { of } from 'rxjs';
import { clickTitle, column, header, openTablePage, sortCount } from '../../../../testing/table-page';
import { Kpi } from '../perform.model';
import { PerformService } from '../perform.service';
import { PerformanceTab } from './performance.tab';

const kpi = (id: number, metric: string, period: string, actual: number | null, attainment: number | null): Kpi => ({
  id,
  employee: { id: 7, full_name: 'X' } as Kpi['employee'],
  metric,
  unit: null,
  period,
  target: 100,
  actual,
  attainment,
  can_edit: false,
});

const kpis = [kpi(1, 'Наймів', '2026-09', 80, 80), kpi(2, 'Відгуки', '2026-10', null, null), kpi(3, 'Анкети', '2026-09', 120, 120)];

function open(url: string): ReturnType<typeof openTablePage<PerformanceTab>> {
  const none = () => of([]);
  return openTablePage(
    PerformanceTab,
    url,
    [{ provide: PerformService, useValue: { objectives: none, kpis: () => of(kpis), plans: none, oneOnOnes: none, employeeResults: none } }],
    { employeeId: 7 },
  );
}

const kpiTable = (el: HTMLElement): HTMLTableElement => el.querySelector('table.kpis') as HTMLTableElement;

describe('PerformanceTab KPI table (header sort and filter)', () => {
  it('gets a header row; a click on «attainment» sorts with empty last, the URL keeps kpi_sort', async () => {
    const { el, router, settle } = await open('/');
    const table = kpiTable(el);
    expect(header(table, 'perform.kpis.attainment').getAttribute('aria-sort')).toBe('none');
    expect(column(table, 0)).toEqual(['Наймів', 'Відгуки', 'Анкети']);
    clickTitle(table, 'perform.kpis.attainment');
    await settle();
    expect(router.url).toBe('/?kpi_sort=attainment&kpi_dir=asc');
    expect(column(table, 0)).toEqual(['Наймів', 'Анкети', 'Відгуки']);
    clickTitle(table, 'perform.kpis.attainment');
    await settle();
    expect(column(table, 0)).toEqual(['Анкети', 'Наймів', 'Відгуки']);
  });

  it('address → view: metric sorted by the interface language, period filtered', async () => {
    const { el, fixture } = await open('/?kpi_sort=metric&kpi_period=2026-09');
    expect(column(kpiTable(el), 0)).toEqual(['Анкети', 'Наймів']);
    expect(sortCount(fixture)).toBe(2); // what an open header filter announces
  });
});
