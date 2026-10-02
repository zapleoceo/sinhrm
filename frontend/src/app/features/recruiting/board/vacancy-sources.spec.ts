import { of } from 'rxjs';
import { clickTitle, column, header, openTablePage } from '../../../../testing/table-page';
import { VacancySourceRow } from '../recruiting.model';
import { RecruitingService } from '../recruiting.service';
import { VacancySources } from './vacancy-sources';

// API order: most applicants first.
const rows: VacancySourceRow[] = [
  { channel_id: 1, name: 'Telegram', added_via: 'webhook', count: 6, share_pct: 60 },
  { channel_id: 2, name: 'Work.ua', added_via: 'manual', count: 3, share_pct: 30 },
  { channel_id: null, name: null, added_via: null, count: 1, share_pct: 10 },
];

function open(url: string): ReturnType<typeof openTablePage<VacancySources>> {
  return openTablePage(VacancySources, url, [{ provide: RecruitingService, useValue: { vacancySources: () => of(rows) } }], { vacancyId: 1 });
}

describe('VacancySources table (header sort and filter)', () => {
  it('is collapsed with the count arrow by default; a click sorts by channel and opens nothing else', async () => {
    const { el, router, settle } = await open('/');
    const table = el.querySelector('table') as HTMLTableElement;
    expect((el.querySelector('details') as HTMLDetailsElement).open).toBe(false);
    expect(header(table, 'recruiting.channels.count').getAttribute('aria-sort')).toBe('descending');
    clickTitle(table, 'recruiting.channels.channel');
    await settle();
    expect(router.url).toBe('/?src_sort=channel&src_dir=asc');
    expect(column(table, 0)).toEqual(['Telegram', 'Work.ua', 'recruiting.channels.none']);
  });

  it('a link with table params opens the block already expanded and filtered', async () => {
    const { el } = await open('/?src_via=manual');
    expect((el.querySelector('details') as HTMLDetailsElement).open).toBe(true);
    expect(column(el.querySelector('table') as HTMLTableElement, 0)).toEqual(['Work.ua']);
  });
});
