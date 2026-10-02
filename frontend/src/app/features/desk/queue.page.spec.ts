import { of } from 'rxjs';
import { clickTitle, column, header, openTablePage } from '../../../testing/table-page';
import { DeskCategory } from './desk.model';
import { DeskService } from './desk.service';
import { DeskQueuePage } from './queue.page';

const cat = (id: number, name: string, first: number | null, active = true): DeskCategory => ({
  id,
  name,
  first_response_hours: first,
  resolve_hours: first === null ? null : first * 3,
  default_assignee_id: null,
  active,
});

// API order: by name.
const categories = [cat(1, 'Відпустки', 8), cat(2, 'Довідки', null, false), cat(3, 'Зарплата', 4)];

function open(url: string): ReturnType<typeof openTablePage<DeskQueuePage>> {
  return openTablePage(DeskQueuePage, url, [{ provide: DeskService, useValue: { queue: () => of([]), categories: () => of(categories) } }]);
}

const catsTable = (el: HTMLElement): HTMLTableElement => el.querySelector('.cats table') as HTMLTableElement;

describe('DeskQueuePage categories table (header sort and filter)', () => {
  it('shows the API order with the arrow on the name; a click on «first response» sorts with empty last', async () => {
    const { el, router, settle } = await open('/');
    const table = catsTable(el);
    expect(header(table, 'desk.categories.name').getAttribute('aria-sort')).toBe('ascending');
    expect(column(table, 0)).toEqual(['Відпустки', 'Довідки', 'Зарплата']);
    clickTitle(table, 'desk.categories.firstResponse');
    await settle();
    expect(router.url).toBe('/?cat_sort=first&cat_dir=asc');
    expect(column(table, 0)).toEqual(['Зарплата', 'Відпустки', 'Довідки']);
    clickTitle(table, 'desk.categories.firstResponse');
    await settle();
    expect(column(table, 0)).toEqual(['Відпустки', 'Зарплата', 'Довідки']);
  });

  it('address → view: the active filter and a text filter come from the URL', async () => {
    const { el } = await open('/?cat_active=false');
    expect(column(catsTable(el), 0)).toEqual(['Довідки']);
    expect(header(catsTable(el), 'desk.categories.active').querySelector('button.filter.active')).not.toBeNull();
  });

  it('a filter that hides every category says so instead of an empty table', async () => {
    const { el } = await open('/?cat_name=zzz');
    expect(column(catsTable(el), 0)).toEqual(['table.noMatches']);
  });
});
