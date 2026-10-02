import { of } from 'rxjs';
import { TablePage } from '../../../../testing/table';
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
  let page: TablePage;
  let archived: boolean[];

  beforeEach(async () => {
    archived = [];
    const api = { list: (a: boolean) => (archived.push(a), of(SCRIPTS)) };
    page = await TablePage.open('admin/scripts', ScriptsPage, '/admin/scripts?channel=call', [{ provide: ScriptsService, useValue: api }]);
  });

  it('filters by channel from the URL; a title click sorts by name A→Z, the second Z→A', async () => {
    expect(page.column(0, 'table', 'a')).toEqual(['Холодний дзвінок', 'Відмова']);
    await page.sort('scripts.name');
    expect(page.params.get('sort')).toBe('name');
    expect(page.column(0, 'table', 'a')).toEqual(['Відмова', 'Холодний дзвінок']);
    await page.sort('scripts.name');
    expect(page.th('scripts.name').getAttribute('aria-sort')).toBe('descending');
    expect(page.column(0, 'table', 'a')).toEqual(['Холодний дзвінок', 'Відмова']);
    expect(archived).toEqual([false]);
  });

  it('active version sorts by its date (none last) and filters by «есть / нет»', async () => {
    await page.navigate('/admin/scripts?sort=active');
    expect(page.column(0, 'table', 'a')).toEqual(['Відмова', 'Холодний дзвінок', 'Анкета']);
    await page.navigate('/admin/scripts?active=no');
    expect(page.column(0, 'table', 'a')).toEqual(['Анкета']);
  });
});
