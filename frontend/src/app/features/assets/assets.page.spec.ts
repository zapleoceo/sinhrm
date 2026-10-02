import { of } from 'rxjs';
import { TablePage } from '../../../testing/table';
import { Asset, AssetQuery } from './assets.model';
import { AssetsPage } from './assets.page';
import { AssetsService } from './assets.service';

const asset = (id: number, inventory: string, name: string, holder: string | null): Asset => ({
  id,
  inventory_number: inventory,
  name,
  serial: null,
  type: { id: 2, name: 'Laptop' },
  status: holder ? 'assigned' : 'in_stock',
  cost: null,
  purchased_at: null,
  notes: null,
  employee: holder ? { id: id * 10, full_name: holder } : null,
});

const ASSETS = [asset(1, 'INV-10', 'MacBook', 'Петренко Іван'), asset(2, 'INV-9', 'ThinkPad', null), asset(3, 'INV-2', 'Dell', 'Андрієнко Олена')];

describe('AssetsPage: sortable / filterable headers bound to the URL', () => {
  let queries: AssetQuery[];
  let page: TablePage;

  beforeEach(async () => {
    queries = [];
    const api = { list: (q: AssetQuery) => (queries.push(q), of(ASSETS)), types: () => of([{ id: 2, name: 'Laptop' }]) };
    page = await TablePage.open('admin/assets', AssetsPage, '/admin/assets?status=assigned&type=2&q=mac&sort=holder&dir=desc', [{ provide: AssetsService, useValue: api }]);
    await new Promise((r) => setTimeout(r, 250)); // the list request is debounced
    await page.settle();
  });

  it('sends the server filters from the URL once, sorts on the page and marks the header', () => {
    expect(queries).toEqual([{ q: 'mac', status: 'assigned', type_id: 2 }]);
    expect(page.th('assets.holder').getAttribute('aria-sort')).toBe('descending');
    expect(page.th('assets.statusLabel').querySelector('.dot')).not.toBeNull();
    // Holder Z→A, the asset without a holder last.
    expect(page.column(5)).toEqual(['Петренко Іван', 'Андрієнко Олена', '—']);
  });

  it('a title click sorts on the page without a request; a page-side filter narrows the rows', async () => {
    await page.sort('assets.inventoryNumber');
    expect(page.params.get('sort')).toBe('inventory');
    expect(page.params.get('dir')).toBe('asc');
    expect(page.column(0)).toEqual(['INV-2', 'INV-9', 'INV-10']);
    await page.sort('assets.inventoryNumber');
    expect(page.column(0)).toEqual(['INV-10', 'INV-9', 'INV-2']);

    await page.navigate('/admin/assets?status=assigned&type=2&q=mac&name=think');
    await new Promise((r) => setTimeout(r, 250));
    expect(queries).toHaveLength(1);
    expect(page.column(1)).toEqual(['ThinkPad']);
  });

  it('a server filter change reloads; junk type ids are not sent', async () => {
    await page.navigate('/admin/assets?status=repair&type=abc');
    await new Promise((r) => setTimeout(r, 250));
    expect(queries.at(-1)).toEqual({ q: undefined, status: 'repair', type_id: undefined });
  });
});
