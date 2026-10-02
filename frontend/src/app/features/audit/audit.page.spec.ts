import { TestBed } from '@angular/core/testing';
import { Router, convertToParamMap, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { of } from 'rxjs';
import { AuditPage as AuditPageData, AuditQuery } from './audit.model';
import { AuditPage } from './audit.page';
import { AUDIT_PAGE_SIZE, auditQueryFromParams } from './audit.query';
import { AuditService } from './audit.service';
import { sortCount } from '../../../testing/table-page';

const PAGE: AuditPageData = {
  data: [{ id: 7, action: 'updated', entity_type: 'employee', entity_id: 3, user: { id: 5, name: 'Ann' }, changes: null, meta: null, created_at: '2026-09-15T10:00:00+00:00' }],
  meta: { current_page: 1, per_page: 20, total: 1, last_page: 1 },
};

describe('audit query in the URL', () => {
  it('reads filters, sort and paging; junk is dropped, a reversed range is swapped', () => {
    expect(
      auditQueryFromParams(
        convertToParamMap({ user_id: '5', entity_type: 'employee', action: 'updated', from: '2026-09-30', to: '2026-09-01', sort: 'user', dir: 'desc', page: '2', perPage: '50' }),
      ),
    ).toEqual({ user_id: 5, entity_type: 'employee', action: 'updated', from: '2026-09-01', to: '2026-09-30', sort: 'user', dir: 'desc', page: 2, perPage: 50 });
    const junk = auditQueryFromParams(
      convertToParamMap({ user_id: 'me', entity_type: 'Robert; drop', action: 'hacked', from: '2026-13-01', sort: 'changes', perPage: '1000' }),
    );
    expect(junk).toEqual({
      user_id: undefined,
      entity_type: undefined,
      action: undefined,
      from: undefined,
      to: undefined,
      sort: undefined,
      dir: undefined,
      page: 1,
      perPage: 100,
    });
    expect(auditQueryFromParams(convertToParamMap({ entity_type: 'x'.repeat(49) })).entity_type).toBeUndefined();
    expect(auditQueryFromParams(convertToParamMap({})).perPage).toBe(AUDIT_PAGE_SIZE);
  });
});

describe('AuditPage: sortable / filterable headers bound to the URL', () => {
  let queries: AuditQuery[];
  let harness: RouterTestingHarness;

  const th = (label: string) =>
    Array.from(harness.routeNativeElement!.querySelectorAll<HTMLTableCellElement>('th')).find((t) => t.getAttribute('aria-label') === label)!;
  const url = () => new URL(TestBed.inject(Router).url, 'http://x');
  const click = async (el: Element | null) => {
    (el as HTMLButtonElement).click();
    await harness.fixture.whenStable();
    harness.detectChanges();
  };

  beforeEach(async () => {
    queries = [];
    TestBed.configureTestingModule({
      imports: [TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [
        provideRouter([{ path: 'admin/audit', component: AuditPage }]),
        {
          provide: AuditService,
          useValue: {
            list: (q: AuditQuery) => (queries.push(q), of(PAGE)),
            options: () => of({ entity_types: ['employee', 'odd_type'], actions: ['updated'], users: [{ id: 5, name: 'Ann' }] }),
          },
        },
      ],
    });
    harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/admin/audit?entity_type=employee&page=2');
    harness.detectChanges();
  });

  it('without ?sort the time column carries the API order (newest first); the URL filter shows on its header', () => {
    expect(queries).toEqual([expect.objectContaining({ entity_type: 'employee', page: 2, sort: undefined })]);
    expect(sortCount(harness.fixture)).toBe(PAGE.meta.total); // the server total, announced by an open filter
    expect(th('audit.columns.time').getAttribute('aria-sort')).toBe('descending');
    expect(th('audit.columns.entity').querySelector('.dot')).not.toBeNull();
    expect(th('audit.columns.changes')).toBeUndefined(); // a plain header: the diff is not sortable
  });

  it('time flips to oldest first; another column starts ascending; the page goes back to 1', async () => {
    await click(th('audit.columns.time').querySelector('button.title'));
    expect(url().searchParams.get('sort')).toBe('time');
    expect(url().searchParams.get('dir')).toBe('asc');
    expect(url().searchParams.has('page')).toBe(false);
    expect(queries.at(-1)).toEqual(expect.objectContaining({ sort: 'time', dir: 'asc', page: 1, entity_type: 'employee' }));

    await click(th('audit.columns.user').querySelector('button.title'));
    expect(queries.at(-1)).toEqual(expect.objectContaining({ sort: 'user', dir: 'asc' }));
    expect(th('audit.columns.user').getAttribute('aria-sort')).toBe('ascending');
    expect(th('audit.columns.time').getAttribute('aria-sort')).toBe('none');
  });

  it('the date range of the time column goes to ?from=&to=', async () => {
    await click(th('audit.columns.time').querySelector('button.filter'));
    const [from, to] = Array.from(document.querySelectorAll<HTMLInputElement>('.popover input[type=date]'));
    from.value = '2026-09-01';
    from.dispatchEvent(new Event('input'));
    to.value = '2026-09-15';
    to.dispatchEvent(new Event('input'));
    document.querySelector<HTMLFormElement>('.popover')!.dispatchEvent(new Event('submit'));
    await harness.fixture.whenStable();
    expect(url().searchParams.get('from')).toBe('2026-09-01');
    expect(url().searchParams.get('to')).toBe('2026-09-15');
    expect(queries.at(-1)).toEqual(expect.objectContaining({ from: '2026-09-01', to: '2026-09-15', page: 1 }));
  });

  it('the entity filter lists translated known types and raw unknown ones', async () => {
    await click(th('audit.columns.entity').querySelector('button.filter'));
    const labels = Array.from(document.querySelectorAll('.popover mat-radio-button')).map((r) => r.textContent?.trim());
    expect(labels).toEqual(['table.filter.all', 'audit.entities.employee', 'odd_type']);
  });
});
