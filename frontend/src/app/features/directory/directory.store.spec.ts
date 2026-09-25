import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable, Subject, of, throwError } from 'rxjs';
import { DictionaryItem, DictionaryPage, DictionaryQuery, DictionaryType, ImportReport } from './directory.model';
import { DirectoryService } from './directory.service';
import { DirectoryStore } from './directory.store';

const item = (id: number, name: string, status: DictionaryItem['status'] = 'active'): DictionaryItem => ({
  id,
  external_id: null,
  name,
  status,
  created_at: null,
  updated_at: null,
});

const page = (data: DictionaryItem[]): DictionaryPage => ({
  data,
  meta: { current_page: 1, per_page: 50, total: data.length, last_page: 1 },
});

class FakeApi {
  calls: { type: DictionaryType; query: DictionaryQuery }[] = [];
  list$: Observable<DictionaryPage> = of(page([]));
  update$ = new Subject<DictionaryItem>();
  import$: Observable<ImportReport> = of({} as ImportReport);
  list = (type: DictionaryType, query: DictionaryQuery) => {
    this.calls.push({ type, query });
    return this.list$;
  };
  update = () => this.update$;
  create = (_type: DictionaryType, body: { name?: string }) => of(item(99, body.name ?? ''));
  import = () => this.import$;
}

describe('DirectoryStore', () => {
  let store: DirectoryStore;
  let api: FakeApi;

  beforeEach(() => {
    api = new FakeApi();
    TestBed.configureTestingModule({ providers: [DirectoryStore, { provide: DirectoryService, useValue: api }] });
    store = TestBed.inject(DirectoryStore);
  });

  it('loads the active tab and switches tabs with a fresh query', () => {
    api.list$ = of(page([item(1, 'A')]));
    store.load();
    expect(store.items().length).toBe(1);
    expect(store.total()).toBe(1);

    store.patchQuery({ q: 'x' });
    store.selectType('positions');
    expect(api.calls.at(-1)).toEqual({ type: 'positions', query: { page: 1, perPage: 50 } });
  });

  it('filters reset the page', () => {
    store.setPage(3, 20);
    store.patchQuery({ status: 'disabled' });
    expect(store.query()).toEqual({ page: 1, perPage: 20, status: 'disabled' });
  });

  it('flags a load error', () => {
    api.list$ = throwError(() => new Error('down'));
    store.load();
    expect(store.failed()).toBe(true);
    expect(store.loading()).toBe(false);
  });

  it('renames optimistically and keeps the server answer', () => {
    api.list$ = of(page([item(1, 'Old')]));
    store.load();

    store.rename(store.items()[0], '  New  ', () => undefined);
    expect(store.items()[0].name).toBe('New');
    expect(store.pending().has(1)).toBe(true);

    api.update$.next(item(1, 'New'));
    expect(store.pending().size).toBe(0);
  });

  it('ignores an empty or unchanged name', () => {
    api.list$ = of(page([item(1, 'Same')]));
    store.load();
    store.rename(store.items()[0], 'Same', () => undefined);
    store.rename(store.items()[0], '   ', () => undefined);
    expect(store.pending().size).toBe(0);
  });

  it('rolls a status toggle back and reports the error key', () => {
    api.list$ = of(page([item(1, 'A')]));
    store.load();
    let reported = '';

    store.toggleStatus(store.items()[0], (key) => (reported = key));
    expect(store.items()[0].status).toBe('disabled');
    api.update$.error(new HttpErrorResponse({ status: 500 }));

    expect(store.items()[0].status).toBe('active');
    expect(reported).toBe('directory.errors.generic');
  });

  it('create reloads the list', () => {
    const before = api.calls.length;
    store.create(' New ').subscribe();
    expect(api.calls.length).toBe(before + 1);
  });

  it('import stores the report and reloads', () => {
    const counts = { created: 2, updated: 0, skipped: 1 };
    api.import$ = of({ branches: counts, cities: counts, departments: counts, positions: counts, total: counts });
    store.runImport();
    expect(store.importReport()?.total.created).toBe(2);
    expect(store.importing()).toBe(false);
    expect(api.calls.length).toBe(1);
  });

  it('import failure keeps a readable error key', () => {
    api.import$ = throwError(() => new HttpErrorResponse({ status: 422, error: { code: 'integration_not_configured' } }));
    store.runImport();
    expect(store.importError()).toBe('directory.errors.integration_not_configured');
    expect(store.importReport()).toBeNull();
    expect(store.importing()).toBe(false);
  });
});
