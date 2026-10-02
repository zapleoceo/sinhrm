import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { of } from 'rxjs';
import { TablePage, clickTitle, column, header, openTablePage, sortCount } from '../../../../testing/table-page';
import { ScriptDetails, ScriptVersion, emptyContent } from '../scripts.model';
import { ScriptsService } from '../scripts.service';
import { ScriptEditorPage } from './script-editor.page';

const version = (n: number, draft: boolean, author: string | null, steps: number, published_at: string | null): ScriptVersion => ({
  id: n * 10,
  script_id: 1,
  version: n,
  is_draft: draft,
  published_at,
  author: author ? { id: n, name: author } : null,
  updated_at: null,
  content: { ...emptyContent(), steps: Array.from({ length: steps }, (_, i) => ({ id: `s${i}`, title: 'S', goal: '', sample: '', required: false, weight: 1, keywords: [] })) },
});
// API order: newest version first; v3 is the draft (no publish date).
const versions = [version(3, true, 'Ольга', 4, null), version(2, false, 'Борис', 2, '2026-09-10T10:00:00Z'), version(1, false, 'Ангеліна', 3, '2026-08-01T10:00:00Z')];
const script: ScriptDetails = { id: 1, name: 'S', channel: 'call', archived: false, active_version: versions[1], draft: null, updated_at: null };

async function open(url: string): Promise<TablePage<ScriptEditorPage>> {
  const page = await openTablePage(
    ScriptEditorPage,
    url,
    [provideHttpClient(), provideHttpClientTesting(), { provide: ScriptsService, useValue: { get: () => of(script), versions: () => of({ versions, activeVersionId: 20 }) } }],
    { id: 1 },
  );
  const tabs = [...page.el.querySelectorAll<HTMLElement>('[role="tab"]')];
  tabs.find((t) => t.textContent?.includes('scripts.tabs.versions'))?.click();
  await page.settle();
  await page.settle();
  return page;
}

const versionsTable = (el: HTMLElement): HTMLTableElement => el.querySelector('table.versions') as HTMLTableElement;

describe('ScriptEditorPage versions table (header sort and filter)', () => {
  it('marks the API order (newest version first); a click on «published» puts the draft last both ways', async () => {
    const { el, router, settle } = await open('/');
    const table = versionsTable(el);
    expect(header(table, 'scripts.versions.version').getAttribute('aria-sort')).toBe('descending');
    expect(column(table, 0)).toEqual(['v3', 'v2', 'v1']);
    clickTitle(table, 'scripts.versions.published');
    await settle();
    expect(router.url).toBe('/?ver_sort=published&ver_dir=asc');
    expect(column(table, 0)).toEqual(['v1', 'v2', 'v3']);
    clickTitle(table, 'scripts.versions.published');
    await settle();
    expect(column(table, 0)).toEqual(['v2', 'v1', 'v3']);
  });

  it('address → view: author sorted by the interface language, steps filtered by range', async () => {
    const { el, fixture } = await open('/?ver_sort=author&ver_steps_from=3');
    expect(column(versionsTable(el), 2)).toEqual(['Ангеліна', 'Ольга']);
    expect(sortCount(fixture)).toBe(2); // what an open header filter announces
  });
});
