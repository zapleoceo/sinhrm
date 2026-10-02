import { of } from 'rxjs';
import { TablePage, clickTitle, header, openTablePage, sortCount } from '../../../../testing/table-page';
import { Script } from '../scripts.model';
import { ScriptsService } from '../scripts.service';
import { ScriptsPage } from './scripts.page';

const version = (published: string | null) => ({ id: 1, version: 1, published_at: published, updated_at: published });
const SCRIPTS: Script[] = [
  { id: 1, name: 'Холодний дзвінок', channel: 'call', archived: false, active_version: version('2026-09-01T00:00:00Z'), draft: null, updated_at: null },
  { id: 2, name: 'Анкета', channel: 'chat', archived: false, active_version: null, draft: version(null), updated_at: null },
  { id: 3, name: 'Відмова', channel: 'call', archived: false, active_version: version('2026-08-01T00:00:00Z'), draft: null, updated_at: null },
];

describe('ScriptsPage: sortable / filterable headers bound to the URL', () => {
  let page: TablePage<ScriptsPage>;
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
  let archived: boolean[];

  beforeEach(async () => {
    archived = [];
    const api = { list: (a: boolean) => (archived.push(a), of(SCRIPTS)) };
    page = await openTablePage(ScriptsPage, '/?channel=call', [{ provide: ScriptsService, useValue: api }]);
  });

  it('filters by channel from the URL; a title click sorts by name A→Z, the second Z→A', async () => {
    expect(cells(0, 'table', 'a')).toEqual(['Холодний дзвінок', 'Відмова']);
    await sort('scripts.name');
    expect(params().get('sort')).toBe('name');
    expect(cells(0, 'table', 'a')).toEqual(['Відмова', 'Холодний дзвінок']);
    await sort('scripts.name');
    expect(header(table(), 'scripts.name').getAttribute('aria-sort')).toBe('descending');
    expect(cells(0, 'table', 'a')).toEqual(['Холодний дзвінок', 'Відмова']);
    expect(archived).toEqual([false]);
  });

  it('active version sorts by its date (none last) and filters by «есть / нет»', async () => {
    await navigate('/?sort=active');
    expect(cells(0, 'table', 'a')).toEqual(['Відмова', 'Холодний дзвінок', 'Анкета']);
    await navigate('/?active=no');
    expect(cells(0, 'table', 'a')).toEqual(['Анкета']);
    expect(sortCount(page.fixture)).toBe(1); // what an open header filter announces
  });
});
