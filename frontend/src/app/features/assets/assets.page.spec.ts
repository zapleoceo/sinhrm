import { of } from 'rxjs';
import { TablePage, clickTitle, column, header, openTablePage, sortCount } from '../../../testing/table-page';
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
const debounce = () => new Promise((r) => setTimeout(r, 250)); // the list request is debounced

describe('AssetsPage: sortable / filterable headers bound to the URL', () => {
  let queries: AssetQuery[];
  let page: TablePage<AssetsPage>;
  const table = () => page.el.querySelector('table')!;
  const open = async (url: string) => {
    page = await openTablePage(AssetsPage, url, [{ provide: AssetsService, useValue: { list: (q: AssetQuery) => (queries.push(q), of(ASSETS)), types: () => of([{ id: 2, name: 'Laptop' }]) } }]);
    await debounce();
    await page.settle();
  };

  beforeEach(() => (queries = []));

  it('sends the server filters from the URL once, sorts on the page and marks the header', async () => {
    await open('/?status=assigned&type=2&q=mac&sort=holder&dir=desc');
    expect(queries).toEqual([{ q: 'mac', status: 'assigned', type_id: 2 }]);
    expect(header(table(), 'assets.holder').getAttribute('aria-sort')).toBe('descending');
    expect(header(table(), 'assets.statusLabel').querySelector('.dot')).not.toBeNull();
    // Holder Z→A; the status also narrows the page (the in-stock asset of the mock is hidden).
    expect(column(table(), 5)).toEqual(['Петренко Іван', 'Андрієнко Олена']);
  });

  it('a title click sorts on the page (asc, then desc) without a new request; page filters narrow the rows', async () => {
    await open('/?name=a');
    expect(header(table(), 'assets.inventoryNumber').getAttribute('aria-sort')).toBe('ascending'); // default order
    clickTitle(table(), 'assets.serial');
    await page.settle();
    expect(page.router.url).toContain('sort=serial');
    clickTitle(table(), 'assets.inventoryNumber');
    await page.settle();
    clickTitle(table(), 'assets.inventoryNumber');
    await page.settle();
    await debounce();
    expect(page.router.url).toContain('dir=desc');
    expect(column(table(), 0)).toEqual(['INV-10', 'INV-9']); // «MacBook», «ThinkPad» contain «a»; numbers compare naturally
    expect(sortCount(page.fixture)).toBe(2); // what an open header filter announces
    expect(queries).toHaveLength(1);
  });

  it('junk status and type in the URL are not sent to the API', async () => {
    await open('/?status=lost&type=abc');
    expect(queries).toEqual([{ q: undefined, status: undefined, type_id: undefined }]);
  });
  it('the search box shows the URL text, but never overwrites what the user is typing (the space between two words stays)', async () => {
    await open('/?q=mac');
    const input = page.el.querySelector<HTMLInputElement>('input[type=search]')!;
    expect(input.value).toBe('mac');
    input.focus();
    input.value = 'mac ';
    input.dispatchEvent(new Event('input'));
    await new Promise((r) => setTimeout(r, 350)); // search debounce → the URL gets the trimmed text
    await page.settle();
    expect(new URL(page.router.url, 'http://x').searchParams.get('q')).toBe('mac');
    expect(input.value).toBe('mac ');
    input.blur();
    await page.router.navigateByUrl('/?q=dell');
    await page.settle();
    expect(input.value).toBe('dell');
  });
});
